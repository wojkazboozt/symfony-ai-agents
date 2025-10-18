<?php

declare(strict_types=1);

namespace Boozt\Bundle\ServiceToolsBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Tool\SimilaritySearch;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Store\Bridge\Postgres\Store;
use Symfony\AI\Store\Document\Loader\InMemoryLoader;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Wojciech Kaźmierczak <wojkaz@boozt.com>
 */
#[AsCommand('boozt:finance-ai:query-postgres', description: 'Test command for SimilaritySearch in Postgres store')]
class PostgresQueryCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $customLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $openaiApiKey,
    ) {
        parent::__construct();
    }

    public function __invoke(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $io->title('Testing SimilaritySearch and Postgres store');

        // initialize the store
        $store = Store::fromDbal(
            connection: $this->entityManager->getConnection(),
            tableName: 'my_table',
        );

        // create embeddings and documents
        $documents = [];

        // initialize the table
        $store->setup();

        // create embeddings for documents
        $platform = PlatformFactory::create($this->openaiApiKey, $this->httpClient);
        $vectorizer = new Vectorizer($platform, 'text-embedding-3-small', $this->customLogger);
        $indexer = new Indexer(new InMemoryLoader($documents), $vectorizer, $store, logger: $this->customLogger);
        $indexer->index($documents);

        $similaritySearch = new SimilaritySearch($vectorizer, $store);
        $toolbox = new Toolbox([$similaritySearch], logger: $this->customLogger);
        $processor = new AgentProcessor($toolbox);
        $agent = new Agent($platform, 'gpt-4o-mini', [$processor], [$processor]);

        $helper = new QuestionHelper();
        $question = new Question('Message:', 'How can you help me?');
        $userMessage = $helper->ask($input, $output, $question);

        $messages = new MessageBag(
            Message::forSystem('Please answer all user questions only using SimilaritySearch function.'),
            Message::ofUser($userMessage)
        );
        $result = $agent->call($messages);

        $io->comment('Displaying result...');

        $io->text($result->getContent());

        return self::SUCCESS;
    }
}
