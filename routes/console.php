<?php

use App\Console\Commands\ApplyContactSunsetCommand;
use App\Console\Commands\CheckCustomLinkDomains;
use App\Console\Commands\CheckCustomTrackingDomainDns;
use App\Console\Commands\DownloadGeoIpDatabaseCommand;
use App\Console\Commands\EnableSesEventDestinationsCommand;
use App\Console\Commands\ProbeVerifiedLinkDomains;
use App\Console\Commands\ProcessVisualAutomationSteps;
use App\Console\Commands\RefreshEmailIdentityDnsStatus;
use App\Console\Commands\SendDueNewsletterContactMails;
use App\Console\Commands\SendDueSequenceStepContactMails;
use App\Console\Commands\SendPermissionAlertCommand;
use App\Console\Commands\SendSequenceBlockedDigestCommand;
use App\Console\Commands\SendWeeklySummaryMail;
use App\Console\Commands\SyncConfigurationSetsCommand;
use App\Models\WebhookDelivery;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schedule;

Schedule::command(RefreshEmailIdentityDnsStatus::class)->everyFifteenMinutes();
Schedule::command(CheckCustomTrackingDomainDns::class)->everyThirtyMinutes()->withoutOverlapping();
Schedule::command(CheckCustomLinkDomains::class)->everyThirtyMinutes()->withoutOverlapping();
Schedule::command(ProbeVerifiedLinkDomains::class)->hourly()->withoutOverlapping();
Schedule::command(ProcessVisualAutomationSteps::class)->everyMinute()->withoutOverlapping();
Schedule::command(SendDueNewsletterContactMails::class)->everyMinute()->withoutOverlapping();
Schedule::command(SendDueSequenceStepContactMails::class)->everyMinute()->withoutOverlapping();
Schedule::command('ses:fetch-quota')->hourly();
Schedule::command(SyncConfigurationSetsCommand::class)->hourly();
Schedule::command(EnableSesEventDestinationsCommand::class)->hourly()->withoutOverlapping();
Schedule::command(SendPermissionAlertCommand::class)->hourly();
Schedule::command(SendSequenceBlockedDigestCommand::class)->dailyAt('09:00');
Schedule::command(DownloadGeoIpDatabaseCommand::class)
    ->days([CarbonInterface::MONDAY, CarbonInterface::THURSDAY])
    ->at('03:00')
    ->when(fn (): bool => filled(config('services.maxmind.license_key')));
Schedule::command(ApplyContactSunsetCommand::class)->dailyAt('04:00')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
Schedule::command(SendWeeklySummaryMail::class)
    ->hourly()
    ->days([
        CarbonInterface::THURSDAY,
        CarbonInterface::FRIDAY,
        CarbonInterface::SATURDAY,
    ])
    ->description('Send the weekly summary on Friday at 17:00 in the time zone of each account');
