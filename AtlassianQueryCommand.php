<?php

declare(strict_types=1);

namespace Boozt\Bundle\ServiceToolsBundle\Command;

use Boozt\Bundle\ServiceToolsBundle\Service\Tool\Atlassian;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\Gemini\PlatformFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
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
#[AsCommand('boozt:finance-ai:query-atlassian', description: 'Test command for querying the Atlassian Api')]
class AtlassianQueryCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $customLogger,
        private readonly string $geminiApiKey,
        private readonly string $atlassianBaseUrl,
        private readonly string $atlassianApiToken,
        private readonly string $atlassianUsername,
    ) {
        parent::__construct();
    }

    public function __invoke(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $io->title('Testing Atlassian API');

        $atlassianClient = $this->httpClient->withOptions([
            'auth_basic' => [$this->atlassianUsername, $this->atlassianApiToken],
        ]);

        $platform = PlatformFactory::create($this->geminiApiKey, $atlassianClient);

        $atlassian = new Atlassian($atlassianClient, $this->atlassianBaseUrl);
        $toolbox = new Toolbox([$atlassian], logger: $this->customLogger);
        $processor = new AgentProcessor($toolbox, includeSources: true);
        $agent = new Agent($platform, 'gemini-2.5-flash', [$processor], [$processor]);

        $helper = new QuestionHelper();
        $question = new Question('Message:', 'How can you help me?');
        $userMessage = $helper->ask($input, $output, $question);

        $io->comment('Connecting to model...');
        $messages = new MessageBag(
            Message::forSystem(<<<TEXT
    Your primary and sole task is to answer user queries.
    
    1.  **Information Sources:** Use **only** the **Jira** and **Confluence** search tools. Do not generate answers from your own knowledge base.
    2.  **JQL Query Format:**
        * **Categorically avoid** using the keyword **`LIMIT`** in the JQL query string.
        * To restrict the number of results, use the **`maxResults`** parameter (or the equivalent API parameter), and not JQL syntax.
        * Ensure the JQL query is properly constructed (e.g., `project = PLT ORDER BY created DESC`).
    3.  **No Results:** If searching **Jira** or **Confluence** returns no results, inform the user that no matching information was found in the available systems.
    4.  **User Response:** Answer **concisely and in natural language**, utilizing the data found. Do not return raw JQL code or full JSON objects.
    TEXT
            ),
            Message::ofUser($userMessage)
        );
        $result = $agent->call($messages);

        $io->comment('Displaying result...');

        $io->text($result->getContent());

        return self::SUCCESS;
    }
}
