<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DockerComposeTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function composeFiles(): array
    {
        return [
            'build' => ['docker-compose.yml'],
            'image' => ['docker-compose.image.yml'],
        ];
    }

    /**
     * When the poller does not run, the SES events stay in SQS and the mails
     * stay in the status sent. Docker must start it again after each exit.
     */
    #[DataProvider('composeFiles')]
    public function test_the_ses_events_poller_always_restarts(string $file): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2).'/'.$file, Yaml::PARSE_CUSTOM_TAGS);

        $service = $compose['services']['ses-events'];

        $this->assertSame('always', $service['restart']);
        $this->assertSame(['php', 'artisan', 'ses-events:poll'], $service['command']);
    }
}
