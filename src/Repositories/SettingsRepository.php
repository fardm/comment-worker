<?php

namespace Repositories;

/**
 * Settings Repository
 * Handles all database operations for settings
 */
class SettingsRepository extends BaseRepository
{
    /**
     * Get a setting value by key
     */
    public function get(string $key): ?string
    {
        $stmt = $this->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : null;
    }

    /**
     * Set a setting value
     */
    public function set(string $key, string $value): bool
    {
        $stmt = $this->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get multiple settings by keys
     */
    public function getMultiple(array $keys): array
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
        $stmt->execute($keys);
        
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
        
        return $settings;
    }

    /**
     * Set multiple settings at once
     */
    public function setMultiple(array $settings): bool
    {
        $stmt = $this->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        
        return true;
    }

    /**
     * Delete a setting by key
     */
    public function delete(string $key): bool
    {
        $stmt = $this->prepare("DELETE FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a setting exists
     */
    public function exists(string $key): bool
    {
        $stmt = $this->prepare("SELECT 1 FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        return $stmt->fetch() !== false;
    }
}
