<?php

namespace Helpers;

/**
 * Validation Helper
 * Provides input validation functions
 */
class ValidationHelper
{
    /**
     * Validate required fields in array
     */
    public static function validateRequired(array $data, array $requiredFields): array
    {
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $errors[] = ucfirst($field) . ' is required';
            }
        }
        
        return $errors;
    }

    /**
     * Validate email
     */
    public static function validateEmail(string $email): ?string
    {
        if (!SecurityHelper::validateEmail($email)) {
            return 'Valid email is required';
        }
        return null;
    }

    /**
     * Validate string length
     */
    public static function validateLength(string $value, int $max, string $fieldName = 'Field'): ?string
    {
        if (strlen($value) > $max) {
            return "$fieldName is too long (max $max characters)";
        }
        return null;
    }

    /**
     * Validate comment content
     */
    public static function validateCommentContent(string $content, int $maxLength = 5000): array
    {
        $errors = [];
        
        if (empty(trim($content))) {
            $errors[] = 'Comment content is required';
        }
        
        if (strlen($content) > $maxLength) {
            $errors[] = "Comment is too long (max $maxLength characters)";
        }
        
        return $errors;
    }

    /**
     * Validate status value
     */
    public static function validateStatus(string $status): bool
    {
        return in_array($status, ['pending', 'approved', 'spam', 'deleted'], true);
    }

    /**
     * Validate reaction type
     */
    public static function validateReactionType(string $reactionType, array $allowedTypes): bool
    {
        return in_array($reactionType, $allowedTypes, true);
    }
}
