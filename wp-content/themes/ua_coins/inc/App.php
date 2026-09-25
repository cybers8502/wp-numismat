<?php

namespace Coins;

class App
{
    public function boot(): void
    {

        new Assets\AssetManager();

        new Security\CorsService();
        new Security\ApiGuardService();

        // Not inside bootAdmin(): the wp-admin drag-and-drop UI is only half of it — the same
        // service re-sorts every term query on the public GraphQL API, which is what carries the
        // admin's ordering through to the apps.
        (new Taxonomy\TermOrderService())->boot();

        // Also not admin-only: these denormalised meta keys are what the catalog's server-side
        // table sort orders by, and they have to stay current on every write path (admin save,
        // importer, price run), not just when someone is looking at wp-admin.
        (new Catalog\SortKeyService())->boot();

        $this->bootAdmin();
        $this->bootRestApi();
        $this->bootGraphQL();
        $this->bootCron();
    }

    private function bootAdmin(): void
    {
        new Admin\ThemeSetupService();

        new Admin\AdminMenuManager();

        (new Admin\PostTypes\CoinPostTypeRegistrar())->boot();
        (new Admin\PostTypes\DesignerPostTypeRegistrar())->boot();
        (new Admin\PostTypes\CoinCollectionPostTypeRegistrar())->boot();

        (new Admin\ACFFieldsManager\CoinACFFieldsManager())->boot();
        (new Admin\ACFFieldsManager\DesignerACFFieldsManager())->boot();
        (new Admin\ACFFieldsManager\CoinCollectionACFFieldsManager())->boot();

        (new Catalog\ManualOverrides())->boot();
    }

    private function bootRestApi(): void
    {
        new Rest\ApiRouter();
    }

    private function bootGraphQL(): void
    {
        if (!function_exists('register_graphql_field')) {
            return;
        }

        (new GraphQL\GraphQLRegistrar())->boot();
        (new GraphQL\ConnectionLimits())->boot();
    }

    private function bootCron(): void
    {
        (new Cron\DailyImportScheduler())->boot();
    }
}
