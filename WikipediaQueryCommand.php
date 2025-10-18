<?php

declare(strict_types=1);

namespace Boozt\Bundle\ServiceToolsBundle\Command;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Tool\Wikipedia;
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

#[AsCommand('app:wikipedia:query', description: 'Test command for querying the Wikipedia.')]
class WikipediaQueryCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $customLogger,
    ) {
        parent::__construct();
    }

    public function __invoke(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $io->title('Testing Wikipedia API');

        $geminiApiKey = 'replace_me';

        $platform = PlatformFactory::create($geminiApiKey, $this->httpClient);

        $wikipedia = new Wikipedia($this->httpClient);
        $toolbox = new Toolbox([$wikipedia], logger: $this->customLogger);
        $processor = new AgentProcessor($toolbox, includeSources: true);
        $agent = new Agent($platform, 'gemini-2.5-flash', [$processor], [$processor]);

        $helper = new QuestionHelper();
        $question = new Question('Message:', 'How can you help me?');
        $userMessage = $helper->ask($input, $output, $question);

        $io->comment('Connecting to model...');
        $messages = new MessageBag(Message::ofUser($userMessage));
        $result = $agent->call($messages);

//        $messages = new MessageBag(
//            Message::forSystem('You are a pirate and you write funny.'),
//            Message::ofUser('Are there any implementations for graphql in symfony, or is it just separate technology?'),
//        );
//        $result = $platform->invoke('gemini-2.0-flash', $messages);

        $io->comment('Displaying result...');

        $io->text($result->getContent());

        return self::SUCCESS;
    }
}
