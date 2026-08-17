const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');
const crypto = require('crypto');

const wranglerTomlPath = path.join(__dirname, '../worker/wrangler.toml');
const schemaPath = path.join(__dirname, '../worker/schema.sql');

function runCommand(command, env = process.env, cwd) {
  try {
    return execSync(command, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'inherit'], env, cwd });
  } catch (error) {
    console.error(`Error executing: ${command}`);
    if (error.stdout) console.error(error.stdout);
    if (error.stderr) console.error(error.stderr);
    process.exit(1);
  }
}

async function prompt(question) {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });
  return new Promise(resolve => {
    rl.question(question, answer => {
      rl.close();
      resolve(answer);
    });
  });
}

async function main() {
  console.log('🚀 Setting up Quartz Standalone Comments server...\n');

  console.log('🔒 Checking Cloudflare authentication...');
  try {
    const whoami = execSync('npx wrangler whoami', { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] });
    if (whoami.includes('You are not authenticated')) {
       console.error('❌ Not logged in to Cloudflare.');
       console.log('\nPlease run: npx wrangler login');
       process.exit(1);
    }
    console.log('✅ Logged in to Cloudflare\n');
  } catch (error) {
    // If it fails but we're in CI/mock, we just check if it contains login text
    if (error.stdout && error.stdout.includes('You are not authenticated')) {
       console.error('❌ Not logged in to Cloudflare.');
       console.log('\nPlease run: npx wrangler login');
       process.exit(1);
    } else if (error.stdout && (error.stdout.includes('logged in') || error.stdout.includes('Getting User settings'))) {
       // if we have some API issue but we are "Getting User settings" we might still be able to proceed?
       // Let's actually just let it proceed if we can't reliably determine it, or throw.
       console.log('⚠️ Could not verify Cloudflare login status. Continuing anyway...');
       if (error.stderr) console.error(error.stderr.trim());
    } else {
       console.error('❌ Not logged in to Cloudflare or failed to verify.');
       if (error.stderr) console.error(error.stderr.trim());
       // fallback, continue
       console.log('⚠️ Continuing anyway...');
    }
  }

  console.log('📦 1. Creating Cloudflare D1 database (comments-db)...');
  let createOutput = '';
  let dbId = '';

  try {
    // Attempt to create the database
    createOutput = execSync('npx wrangler d1 create comments-db', { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] });
    console.log(createOutput);

    // Extract database_id from creation output
    const match = createOutput.match(/database_id[=:\s]+"([0-9a-fA-F-]+)"/);
    if (match && match[1]) {
      dbId = match[1];
    } else {
      const uuidMatch = createOutput.match(/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/);
      if (uuidMatch) {
        dbId = uuidMatch[0];
      }
    }
  } catch (error) {
    const stderr = error.stderr || '';
    const stdout = error.stdout || '';
    const fullError = stderr + '\n' + stdout;

    if (fullError.includes('already exists') || fullError.includes('error: 7404') || fullError.includes('already_exists')) {
      console.log('Database already exists. Attempting to retrieve info...');
      try {
        const listOutput = execSync('npx wrangler d1 list --json', { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] });
        const dbs = JSON.parse(listOutput);
        const commentsDb = dbs.find(db => db.name === 'comments-db');

        if (commentsDb && commentsDb.uuid) {
          dbId = commentsDb.uuid;
        } else {
          console.error('❌ Could not find "comments-db" in the database list.');
          process.exit(1);
        }
      } catch (listError) {
        console.error('❌ Failed to list D1 databases.');
        if (listError.stderr) console.error(listError.stderr);
        if (listError.stdout) console.error(listError.stdout);
        process.exit(1);
      }
    } else {
      console.error('❌ Failed to create D1 database.');
      console.error(fullError.trim());
      process.exit(1);
    }
  }

  if (!dbId) {
    console.error('❌ Could not parse database_id from output.');
    process.exit(1);
  }

  console.log(`✅ Found Database ID: ${dbId}\n`);

  console.log('🔧 2. Updating worker/wrangler.toml...');
  let toml = fs.readFileSync(wranglerTomlPath, 'utf-8');
  toml = toml.replace(/database_id\s*=\s*"[^"]*"/g, `database_id = "${dbId}"`);
  fs.writeFileSync(wranglerTomlPath, toml);
  console.log('✅ Updated database_id in wrangler.toml\n');

  console.log('🏗️ 3. Initializing database schema...');
  console.log('   -> Local database');
  runCommand(`npx wrangler d1 execute comments-db --local --file="${schemaPath}"`, { ...process.env, PWD: path.join(__dirname, '../worker') }, path.join(__dirname, '../worker'));

  console.log('   -> Remote database');
  // Since remote might not be set up yet or might ask for confirmation, use --yes if available or stdio inherit
  try {
     execSync(`npx wrangler d1 execute comments-db --remote --file="${schemaPath}"`, { encoding: 'utf-8', stdio: 'inherit', cwd: path.join(__dirname, '../worker') });
  } catch(e) {
     console.log("Remote database execution might have failed or needs confirmation. Continuing.");
  }
  console.log('✅ Schema initialized\n');

  console.log('🔐 4. Admin Account Setup');
  let password = await prompt('Enter a new admin password: ');
  while (!password || password.trim().length < 4) {
    console.log('Password must be at least 4 characters long.');
    password = await prompt('Enter a new admin password: ');
  }

  const hash = crypto.createHash('sha256').update(password).digest('hex');
  const tmpSqlPath = path.join(__dirname, '../worker/tmp.sql');
  fs.writeFileSync(tmpSqlPath, `UPDATE settings SET value = '${hash}' WHERE key = 'admin_password_hash';`);

  console.log('\nSaving admin password to local database...');
  runCommand(`npx wrangler d1 execute comments-db --local --file="tmp.sql"`, { ...process.env, PWD: path.join(__dirname, '../worker') }, path.join(__dirname, '../worker'));

  console.log('Saving admin password to remote database...');
  try {
     execSync(`npx wrangler d1 execute comments-db --remote --file="tmp.sql"`, { encoding: 'utf-8', stdio: 'inherit', cwd: path.join(__dirname, '../worker') });
  } catch(e) {
     console.log("Could not update remote database right now. If you deploy, run this command manually:");
     console.log(`cd worker && npx wrangler d1 execute comments-db --remote --command="UPDATE settings SET value='${hash}' WHERE key='admin_password_hash';"`);
  }

  fs.unlinkSync(tmpSqlPath);
  console.log('\n✅ Setup Complete!');
  console.log('\nYou can now start the local development server with:');
  console.log('  npm run dev');
  console.log('\nOr deploy to production with:');
  console.log('  npm run deploy\n');
}

main();
