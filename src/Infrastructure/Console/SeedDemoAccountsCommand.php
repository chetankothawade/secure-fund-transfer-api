<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:demo-accounts',
    description: 'Seed demo accounts for local API testing.',
)]
final class SeedDemoAccountsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accounts = [
            [
                'id' => '11111111-1111-1111-1111-111111111111',
                'owner_name' => 'Alice',
                'balance' => '1000.0000',
                'currency' => 'USD',
            ],
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'owner_name' => 'Bob',
                'balance' => '100.0000',
                'currency' => 'USD',
            ],
        ];

        foreach ($accounts as $account) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO accounts (id, owner_name, balance, currency)
                    VALUES (:id, :owner_name, :balance, :currency)
                    ON DUPLICATE KEY UPDATE
                        owner_name = VALUES(owner_name),
                        balance = VALUES(balance),
                        currency = VALUES(currency)
                SQL,
                $account
            );
        }

        $io->success('Demo accounts seeded.');

        return Command::SUCCESS;
    }
}
