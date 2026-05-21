<?php

declare(strict_types=1);

namespace Syriable\Ledger\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * php artisan make:posting OrderPaidPosting
 *
 * Scaffolds a Posting subclass at app/Ledger/Postings/{Name}.php.
 */
final class MakePostingCommand extends GeneratorCommand
{
    protected $name = 'make:posting';

    protected $description = 'Create a new ledger Posting class';

    protected $type = 'Posting';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/posting.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Ledger\\Postings';
    }

    /**
     * @return list<array{0:string,1:int,2:string}>
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the Posting class'],
        ];
    }
}
