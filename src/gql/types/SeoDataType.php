<?php

namespace anvildev\simpleseo\gql\types;

use anvildev\simpleseo\fields\data\SeoData;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for the raw SEO field value.
 *
 * `socialImageUrl` resolves the asset URL directly — ether couldn't even
 * deliver the social image over GraphQL (ethercreative/seo#363, #372).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class SeoDataType
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
            'description' => 'The raw Simple SEO field value (no fallbacks applied — see simpleSeo for resolved meta).',
            'fields' => [
                'title' => Type::string(),
                'description' => Type::string(),
                'socialImageId' => Type::int(),
                'socialImageUrl' => [
                    'type' => Type::string(),
                    'resolve' => static fn(SeoData $value): ?string => $value->getSocialImage()?->getUrl(),
                ],
                'noindex' => Type::nonNull(Type::boolean()),
                'nofollow' => Type::nonNull(Type::boolean()),
                'canonical' => Type::string(),
                'robotsDirectives' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'robots' => [
                    'type' => Type::string(),
                    'description' => 'The full robots directive string for this element — noindex/nofollow plus any extra directives — or null when it asks for nothing unusual.',
                    'resolve' => static fn(SeoData $value): ?string => $value->robots(),
                ],
            ],
        ]));
    }

    /**
     * The GraphQL type name.
     */
    public static function getName(): string
    {
        return 'simpleSeo_SeoData';
    }
}
