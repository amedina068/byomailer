<?php

namespace App\Support;

use App\Models\Client;

/**
 * The values that an account needs to make the CloudFormation stack of its
 * IAM role.
 */
class AwsRoleSetup
{
    /**
     * Get the stack values, or null when the server has no role principal.
     *
     * @return array{externalId: string, principalArn: string, roleName: string, templateUrl: string, quickCreateUrl: string|null}|null
     */
    public static function for(Client $client): ?array
    {
        if (blank(config('services.ses_role.principal_arn'))) {
            return null;
        }

        $values = [
            'externalId' => $client->awsExternalId(),
            'principalArn' => (string) config('services.ses_role.principal_arn'),
            'roleName' => (string) config('services.ses_role.role_name'),
        ];

        return [
            ...$values,
            'templateUrl' => url('aws/byomailer-ses-role.json'),
            'quickCreateUrl' => self::quickCreateUrl($values),
        ];
    }

    /**
     * A quick-create link needs the template in Amazon S3. Without a template
     * URL, the account uploads the template file in the console.
     *
     * @param  array{externalId: string, principalArn: string, roleName: string}  $values
     */
    private static function quickCreateUrl(array $values): ?string
    {
        $templateUrl = config('services.ses_role.template_url');

        if (blank($templateUrl)) {
            return null;
        }

        $region = config('services.ses_role.region');

        return "https://{$region}.console.aws.amazon.com/cloudformation/home?region={$region}#/stacks/create/review?".http_build_query([
            'templateURL' => $templateUrl,
            'stackName' => $values['roleName'],
            'param_TrustedPrincipalArn' => $values['principalArn'],
            'param_ExternalId' => $values['externalId'],
            'param_RoleName' => $values['roleName'],
        ]);
    }
}
