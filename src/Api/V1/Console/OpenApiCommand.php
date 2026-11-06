<?php

declare(strict_types=1);

namespace App\Api\V1\Console;

use App\Api\V1\OpenApi\OpenApiGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'api:openapi', description: 'Generate the OpenAPI 3.1 document (runtime/api/openapi.json, served only through the private /api/v1/openapi.json) from API v1 attributes')]
final class OpenApiCommand extends Command
{
    public function __construct(private readonly OpenApiGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stdout', null, InputOption::VALUE_NONE, 'Print the document instead of writing the file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('stdout')) {
            $output->writeln($this->generator->generate());
            return Command::SUCCESS;
        }
        $bytes = $this->generator->write();
        foreach ($this->generator->warnings() as $w) {
            $io->warning($w);
        }
        $doc = json_decode((string) file_get_contents($this->generator->outputFile()), true);
        $io->success(sprintf('Wrote %s (%d bytes, %d paths).', $this->generator->outputFile(), $bytes, count($doc['paths'] ?? [])));
        return Command::SUCCESS;
    }
}
