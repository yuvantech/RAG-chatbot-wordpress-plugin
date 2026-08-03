<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines the custom capability that gates every admin screen and (later)
 * REST endpoint, instead of hard-coding `manage_options` at every call
 * site. This lets a site owner delegate chatbot management to an editor
 * role without granting full administrator access.
 */
final class Capabilities
{
    public const MANAGE = 'manage_ai_chatbot';

    /**
     * Reserved for future filterable capability wiring (e.g. read-only
     * "view analytics" vs. "manage settings" split). Kept as an explicit
     * hook point so later phases don't need to touch call sites.
     */
    public static function register(): void
    {
    }

    public static function grantToAdministrators(): void
    {
        $role = get_role('administrator');

        if ($role !== null && !$role->has_cap(self::MANAGE)) {
            $role->add_cap(self::MANAGE);
        }
    }

    public static function currentUserCan(): bool
    {
        return current_user_can(self::MANAGE);
    }
}
