<?php

namespace Coins\GraphQL;

use GraphQL\Error\UserError;

class AuthGraphQL
{
    public function registerTypes(): void
    {
        $this->registerMutations();
    }

    private function registerMutations(): void
    {
        // refreshJwtAuthToken is already registered by the WPGraphQL JWT Authentication plugin.
        // No need to re-register it here.

        register_graphql_mutation('logout', [
            'description'         => 'Revoke the current user\'s JWT secret, invalidating all active tokens.',
            'inputFields'         => [],
            'outputFields'        => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => [$this, 'resolveLogout'],
        ]);
    }

    public function resolveLogout(): array
    {
        if (!is_user_logged_in()) {
            throw new UserError('You must be logged in to logout.');
        }

        $user_id = get_current_user_id();
        update_user_meta($user_id, 'graphql_jwt_auth_secret_revoked', 1);

        return ['success' => true];
    }
}
