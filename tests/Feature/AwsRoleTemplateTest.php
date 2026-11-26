<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CloudFormation template makes the IAM role of an account. A change to
 * the AWS calls of the app must change the action list here and in the
 * template together.
 */
class AwsRoleTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The SES and SNS actions that the app calls with the credentials of an
     * account. `sts:GetCallerIdentity` needs no permission.
     */
    private const SES_ACTIONS = [
        'ses:CreateConfigurationSet',
        'ses:CreateConfigurationSetEventDestination',
        'ses:CreateEmailIdentity',
        'ses:CreateEmailTemplate',
        'ses:CreateTenant',
        'ses:CreateTenantResourceAssociation',
        'ses:DeleteConfigurationSet',
        'ses:DeleteConfigurationSetTrackingOptions',
        'ses:DeleteEmailTemplate',
        'ses:DeleteTenantResourceAssociation',
        'ses:GetAccount',
        'ses:GetConfigurationSet',
        'ses:GetConfigurationSetEventDestinations',
        'ses:GetEmailIdentity',
        'ses:GetTenant',
        'ses:ListConfigurationSets',
        'ses:ListEmailIdentities',
        'ses:ListTemplates',
        'ses:PutConfigurationSetTrackingOptions',
        'ses:PutEmailIdentityMailFromAttributes',
        'ses:SendBulkEmail',
        'ses:SendRawEmail',
        'ses:UpdateConfigurationSetEventDestination',
        'ses:UpdateReputationEntityPolicy',
    ];

    private const SNS_ACTIONS = [
        'sns:CreateTopic',
        'sns:DeleteTopic',
        'sns:Subscribe',
        'sns:Unsubscribe',
    ];

    public function test_the_template_is_valid_json_with_the_cloudformation_sections(): void
    {
        $template = $this->template();

        $this->assertSame('2010-09-09', $template['AWSTemplateFormatVersion']);
        $this->assertSame(['TrustedPrincipalArn', 'ExternalId', 'RoleName'], array_keys($template['Parameters']));
        $this->assertSame('AWS::IAM::Role', $template['Resources']['ByoMailerRole']['Type']);
        $this->assertSame(['Fn::GetAtt' => ['ByoMailerRole', 'Arn']], $template['Outputs']['RoleArn']['Value']);
    }

    public function test_only_the_trusted_principal_with_the_external_id_can_assume_the_role(): void
    {
        $trust = $this->role()['AssumeRolePolicyDocument']['Statement'];

        $this->assertCount(1, $trust);
        $this->assertSame('Allow', $trust[0]['Effect']);
        $this->assertSame('sts:AssumeRole', $trust[0]['Action']);
        $this->assertSame(['AWS' => ['Ref' => 'TrustedPrincipalArn']], $trust[0]['Principal']);
        $this->assertSame(['StringEquals' => ['sts:ExternalId' => ['Ref' => 'ExternalId']]], $trust[0]['Condition']);
    }

    public function test_the_role_gets_only_the_ses_and_sns_actions_of_the_app(): void
    {
        $statements = $this->role()['Policies'][0]['PolicyDocument']['Statement'];

        $this->assertCount(2, $statements);
        $this->assertSame(self::SES_ACTIONS, $statements[0]['Action']);
        $this->assertSame(self::SNS_ACTIONS, $statements[1]['Action']);
        $this->assertSame(
            ['Fn::Sub' => 'arn:${AWS::Partition}:sns:*:${AWS::AccountId}:mailautomation-*'],
            $statements[1]['Resource'],
        );

        foreach ($statements as $statement) {
            $this->assertSame('Allow', $statement['Effect']);

            foreach ($statement['Action'] as $action) {
                $this->assertStringNotContainsString('*', $action);
                $this->assertDoesNotMatchRegularExpression('/^(iam|sts):/', $action);
            }
        }
    }

    public function test_the_sessions_last_one_hour_at_most(): void
    {
        $this->assertSame(3600, $this->role()['MaxSessionDuration']);
    }

    public function test_the_parameter_rules_accept_the_values_that_byomailer_gives(): void
    {
        $parameters = $this->template()['Parameters'];
        $externalId = Client::factory()->create()->awsExternalId();

        $this->assertMatchesRegularExpression('#'.$parameters['ExternalId']['AllowedPattern'].'#', $externalId);
        $this->assertArrayNotHasKey('NoEcho', $parameters['ExternalId'], 'A quick-create link cannot fill a NoEcho parameter.');
        $this->assertMatchesRegularExpression('#'.$parameters['TrustedPrincipalArn']['AllowedPattern'].'#', 'arn:aws:iam::111122223333:user/byomailer');
        $this->assertMatchesRegularExpression('#'.$parameters['TrustedPrincipalArn']['AllowedPattern'].'#', 'arn:aws:iam::111122223333:role/byomailer-server');
        $this->assertSame(config('services.ses_role.role_name'), $parameters['RoleName']['Default']);
    }

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return json_decode(file_get_contents(public_path('aws/byomailer-ses-role.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function role(): array
    {
        return $this->template()['Resources']['ByoMailerRole']['Properties'];
    }
}
