<?php

namespace Boozt\Bundle\ServiceToolsBundle\Service\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesInterface;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesTrait;
use Symfony\AI\Agent\Toolbox\Source\Source;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsTool('confluence_search', description: 'Search Confluence content using CQL', method: 'search')]
#[AsTool('confluence_get_page', description: 'Get content of a specific page', method: 'getPage')]
#[AsTool('jira_search', description: 'Search Jira issues using JQL', method: 'searchIssues')]
#[AsTool('jira_get_issue', description: 'Get details of a specific Jira issue', method: 'getIssue')]
final class Atlassian implements HasSourcesInterface
{
    use HasSourcesTrait;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
    ) {
    }

    /**
     * @param string $cql The CQL query to search Confluence content
     */
    public function search(string $cql): string
    {
        $result = $this->execute('/wiki/rest/api/content/search', [
            'cql' => $cql,
            'limit' => 10,
        ]);

        if (!isset($result['results']) || [] === $result['results']) {
            return 'No pages were found in Confluence.';
        }

        $response = 'Pages with the following titles were found in Confluence:' . \PHP_EOL;
        foreach ($result['results'] as $page) {
            $response .= ' - ' . $page['title'] . ' (ID: ' . $page['id'] . ')' . \PHP_EOL;
        }

        return $response . \PHP_EOL . 'Use the page ID with tool "confluence_get_page" to load the content.';
    }

    /**
     * @param string $pageId The ID of the page to load from Confluence
     */
    public function getPage(string $pageId): string
    {
        try {
            $page = $this->execute('/wiki/rest/api/content/' . $pageId, [
                'expand' => 'body.storage,version,space',
            ]);

            if (!isset($page['id'])) {
                return \sprintf('No page with ID "%s" was found in Confluence.', $pageId);
            }

            $content = $this->stripHtml($page['body']['storage']['value'] ?? '');
            $pageUrl = $this->baseUrl . $page['_links']['webui'];

            $this->addSource(
                new Source($page['title'], $pageUrl, $content)
            );

            $result = 'This is the content of page "' . $page['title'] . '":' . \PHP_EOL;
            $result .= 'Space: ' . ($page['space']['name'] ?? 'Unknown') . \PHP_EOL;
            $result .= 'Last modified: ' . ($page['version']['when'] ?? 'Unknown') . \PHP_EOL;
            $result .= \PHP_EOL . $content;

            return $result;
        } catch (\Exception $e) {
            return \sprintf('Error retrieving page with ID "%s": %s', $pageId, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function execute(string $endpoint, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        $options = ['query' => $query];

        $response = $this->httpClient->request('GET', $url, $options);

        try {
            return $response->toArray();
        } catch (\Exception $e) {
            // Try to get error details from Confluence API
            $content = $response->getContent(false);
            throw new \RuntimeException(
                \sprintf('Confluence API error: %s. Response: %s', $e->getMessage(), $content),
                0,
                $e
            );
        }
    }

    private function stripHtml(string $html): string
    {
        // Remove HTML tags and decode entities
        $text = strip_tags($html);
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * @param string $jql The JQL query to search Jira issues
     */
    public function searchIssues(string $jql): string
    {
        $result = $this->execute('/rest/api/3/search/jql', [
            'jql' => $jql,
            'maxResults' => 10,
            'fields' => 'summary,status,assignee,priority,issuetype',
        ]);

        if (!isset($result['issues']) || [] === $result['issues']) {
            return 'No issues were found in Jira.';
        }

        $response = 'Issues found in Jira:' . \PHP_EOL;
        foreach ($result['issues'] as $issue) {
            $response .= ' - ' . $issue['key'] . ': ' . $issue['fields']['summary'];
            $response .= ' [' . ($issue['fields']['status']['name'] ?? 'Unknown') . ']';
            $response .= \PHP_EOL;
        }

        return $response . \PHP_EOL . 'Use the issue key with tool "jira_get_issue" to load full details.';
    }

    /**
     * @param string $issueKey The key of the issue to load from Jira (e.g., PROJECT-123)
     */
    public function getIssue(string $issueKey): string
    {
        try {
            $issue = $this->execute('/rest/api/3/issue/' . $issueKey, [
                'fields' => 'summary,description,status,assignee,priority,issuetype,reporter,created,updated,comment',
            ]);

            if (!isset($issue['key'])) {
                return \sprintf('No issue with key "%s" was found in Jira.', $issueKey);
            }

            $fields = $issue['fields'];
            $issueUrl = $this->baseUrl . '/browse/' . $issue['key'];

            $description = '';
            if (isset($fields['description'])) {
                // Handle Atlassian Document Format (ADF) or plain text
                if (\is_array($fields['description'])) {
                    $description = $this->extractTextFromAdf($fields['description']);
                } else {
                    $description = $fields['description'];
                }
            }

            $this->addSource(
                new Source(
                    $issue['key'] . ': ' . $fields['summary'],
                    $issueUrl,
                    $description
                )
            );

            $result = 'Issue: ' . $issue['key'] . \PHP_EOL;
            $result .= 'Summary: ' . $fields['summary'] . \PHP_EOL;
            $result .= 'Type: ' . ($fields['issuetype']['name'] ?? 'Unknown') . \PHP_EOL;
            $result .= 'Status: ' . ($fields['status']['name'] ?? 'Unknown') . \PHP_EOL;
            $result .= 'Assignee: ' . ($fields['assignee']['displayName'] ?? 'Unassigned') . \PHP_EOL;

            if ($description) {
                $result .= \PHP_EOL . 'Description:' . \PHP_EOL . $description . \PHP_EOL;
            }

            // Add comments if available
            if (isset($fields['comment']['comments']) && \count($fields['comment']['comments']) > 0) {
                $result .= \PHP_EOL . 'Recent Comments:' . \PHP_EOL;
                foreach (\array_slice($fields['comment']['comments'], -3) as $comment) {
                    $author = $comment['author']['displayName'] ?? 'Unknown';
                    $body = \is_array($comment['body'])
                        ? $this->extractTextFromAdf($comment['body'])
                        : $comment['body'];
                    $result .= ' - ' . $author . ': ' . $body . \PHP_EOL;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return \sprintf('Error retrieving issue with key "%s": %s', $issueKey, $e->getMessage());
        }
    }

    /**
     * Extract plain text from Atlassian Document Format (ADF)
     *
     * @param array<string, mixed> $adf
     */
    private function extractTextFromAdf(array $adf): string
    {
        $text = '';

        if (isset($adf['content']) && \is_array($adf['content'])) {
            foreach ($adf['content'] as $node) {
                $text .= $this->extractTextFromAdfNode($node) . \PHP_EOL;
            }
        }

        return trim($text);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function extractTextFromAdfNode(array $node): string
    {
        $text = '';

        if (isset($node['text'])) {
            $text .= $node['text'];
        }

        if (isset($node['content']) && \is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= $this->extractTextFromAdfNode($child);
            }
        }

        return $text;
    }
}
