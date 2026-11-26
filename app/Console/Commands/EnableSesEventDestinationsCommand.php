<?php

namespace App\Console\Commands;

use App\Exceptions\AwsCredentialsException;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Services\SesConfigurationSetService;
use App\Services\SesCredentials;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SES disables the SNS event destination of a configuration set when it cannot
 * publish to the topic, for example while AWS suspends the account. SES does
 * not enable it again. Without this command, no delivery, open, bounce or
 * complaint event arrives, and the mails stay in the status sent.
 */
class EnableSesEventDestinationsCommand extends Command
{
    protected $signature = 'ses:enable-event-destinations';

    protected $description = 'Enable the SES event destinations that AWS disabled';

    public function handle(SesConfigurationSetService $configService, SesCredentials $sesCredentials): int
    {
        $credentials = EmailCredential::withoutGlobalScopes()
            ->where('type', 'ses')
            ->whereNotNull('client_id')
            ->get();

        $enabled = 0;
        $failed = 0;

        foreach ($credentials as $credential) {
            [$clientEnabled, $clientFailed] = Tenant::run(
                $credential->client_id,
                fn (): array => $this->enableForCredential($configService, $sesCredentials, $credential),
            );

            $enabled += $clientEnabled;
            $failed += $clientFailed;
        }

        $this->info("Enabled {$enabled} event destination(s). Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} The destinations that were enabled and the configuration sets that failed.
     */
    private function enableForCredential(SesConfigurationSetService $configService, SesCredentials $sesCredentials, EmailCredential $credential): array
    {
        try {
            $aws = $sesCredentials->for($credential);
        } catch (AwsCredentialsException $exception) {
            $this->warn("  Client {$credential->client_id}: {$exception->getMessage()}");

            return [0, 1];
        }

        $configurationSets = EmailIdentity::withoutGlobalScopes()
            ->where('client_id', $credential->client_id)
            ->whereNotNull('configuration_set_name')
            ->get(['configuration_set_name', 'region'])
            ->unique('configuration_set_name');

        $enabled = 0;
        $failed = 0;

        foreach ($configurationSets as $identity) {
            $result = $configService->enableDisabledSnsEventDestinations(
                $identity->configuration_set_name,
                $aws->getAccessKeyId(),
                $aws->getSecretKey(),
                $identity->region ?: $credential->region,
                $aws->getSecurityToken(),
            );

            if (! $result['success']) {
                $this->warn("  {$identity->configuration_set_name}: {$result['error']}");
                $failed++;

                continue;
            }

            foreach ($result['enabled'] ?? [] as $destinationName) {
                Log::warning('EnableSesEventDestinationsCommand: enabled an SES event destination that AWS disabled', [
                    'client_id' => $credential->client_id,
                    'configuration_set' => $identity->configuration_set_name,
                    'event_destination' => $destinationName,
                ]);

                $this->line("  {$identity->configuration_set_name} / {$destinationName}: enabled");
                $enabled++;
            }
        }

        return [$enabled, $failed];
    }
}
