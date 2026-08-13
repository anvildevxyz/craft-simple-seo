<?php

namespace anvildev\simpleseo\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for fully RESOLVED meta — the fallback chain applied, the
 * title format applied, canonical and robots final. The same ResolvedMeta
 * model that backs Twig rendering resolves this type, so headless consumers
 * can never drift from the tag output.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class ResolvedMetaType
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the registered type, creating it on first use.
     */
    public static function getType(): Type
    {
        /** @var Type */
        return GqlEntityRegistry::getOrCreate(self::getName(), static fn(): ObjectType => new ObjectType([
            'name' => self::getName(),
            'description' => 'Fully resolved Simple SEO meta: every fallback applied, every value final.',
            'fields' => [
                'title' => Type::nonNull(Type::string()),
                'socialTitle' => Type::nonNull(Type::string()),
                'description' => Type::string(),
                'canonical' => Type::string(),
                'robots' => Type::string(),
                'ogType' => Type::nonNull(Type::string()),
                'ogSiteName' => Type::nonNull(Type::string()),
                'ogImageUrl' => Type::string(),
                'twitterCard' => Type::nonNull(Type::string()),
            ],
        ]));
    }

    /**
     * The GraphQL type name.
     */
    public static function getName(): string
    {
        return 'simpleSeo_ResolvedMeta';
    }
}
