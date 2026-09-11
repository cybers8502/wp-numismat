<?php

namespace Coins;

class App
{
    public function boot(): void
    {

        new Assets\AssetManager();

        new Security\CorsService();
        new Security\ApiGuardService();

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
