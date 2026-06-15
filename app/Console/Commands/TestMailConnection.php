<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestMailConnection extends Command
{
    protected $signature = 'mail:test-connection {--send : Send a test email to MAIL_FROM_ADDRESS}';

    protected $description = 'Test TCP connectivity to the configured SMTP host (diagnose hosting blocks)';

    public function handle()
    {
        $driver = config('mail.driver');
        $host = config('mail.host');
        $port = (int) config('mail.port');

        $this->info("Mail driver: {$driver}");
        $this->info("SMTP host: {$host}:{$port} (" . config('mail.encryption') . ')');

        if ($driver === 'sendmail') {
            $this->info('Sendmail path: ' . config('mail.sendmail'));
            $this->comment('Sendmail uses the local server mail relay — no external SMTP port test needed.');
        } elseif ($driver === 'mailgun') {
            $this->info('Mailgun domain: ' . config('services.mailgun.domain'));
            $this->comment('Mailgun uses HTTPS API (port 443), not blocked SMTP ports.');
        } elseif ($driver === 'log') {
            $this->warn('MAIL driver is "log" — emails are written to logs only.');
            return 0;
        }

        if ($driver === 'smtp' && $host) {
            $this->line('');
            $this->info("Testing TCP connection to {$host}:{$port} ...");

            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                (int) config('mail.timeout', 10)
            );

            if ($socket) {
                fclose($socket);
                $this->info('SUCCESS: Server can reach SMTP host on this port.');
            } else {
                $this->error("FAILED: Cannot connect — {$errstr} ({$errno})");
                $this->line('');
                $this->comment('Your host blocks outbound SMTP to this server. Use one of:');
                $this->comment('  1. Hosting email SMTP: MAIL_HOST=mail.yourdomain.com');
                $this->comment('  2. Local relay: MAIL_HOST=localhost MAIL_PORT=25 MAIL_ENCRYPTION=null');
                $this->comment('  3. Sendmail: MAIL_DRIVER=sendmail');
                $this->comment('  4. Mailgun API: MAIL_DRIVER=mailgun (uses HTTPS, not SMTP ports)');
                return 1;
            }
        }

        if ($this->option('send')) {
            $to = config('mail.from.address');
            if (empty($to)) {
                $this->error('Set MAIL_FROM_ADDRESS before using --send');
                return 1;
            }

            try {
                \Mail::raw('ERP mail test at ' . now(), function ($message) use ($to) {
                    $message->to($to)->subject('ERP Mail Test');
                });
                $this->info("Test email sent to {$to}");
            } catch (\Throwable $e) {
                $this->error('Send failed: ' . $e->getMessage());
                return 1;
            }
        }

        return 0;
    }
}
