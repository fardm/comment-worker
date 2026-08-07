<?php

namespace Helpers;

/**
 * Reaction Helper
 * Provides reaction-related utility functions
 */
class ReactionHelper
{
    /**
     * Get reaction definitions
     */
    public static function getReactionDefinitions(): array
    {
        return [
            'thumbsup'  => ['emoji' => '👍',  'label' => '👍 thumbs up'],
            'lightbulb' => ['emoji' => '👎',  'label' => '👎 thumbs down'],
            'pray'      => ['emoji' => '🙏',  'label' => '🙏 prayer'],
            'ok'        => ['emoji' => '👌',  'label' => '👌 okay'],
            'fire'      => ['emoji' => '🔥',  'label' => '🔥 fire'],
            'heart'     => ['emoji' => '❤️',  'label' => '❤️ heart'],
            'frown'     => ['emoji' => '☹️',  'label' => '☹️ frown'],
            'rage'      => ['emoji' => '😡',  'label' => '😡 angry'],
            'funny'     => ['emoji' => '😄',  'label' => '😄 laugh'],
            'neutral'   => ['emoji' => '😐',  'label' => '😐 neutral'],
        ];
    }

    /**
     * Get allowed reaction types
     */
    public static function getAllowedReactionTypes(): array
    {
        return array_keys(self::getReactionDefinitions());
    }

    /**
     * Get reaction email label
     */
    public static function getReactionEmailLabel(string $reactionType): string
    {
        $defs = self::getReactionDefinitions();
        return $defs[$reactionType]['label'] ?? $reactionType;
    }
}
