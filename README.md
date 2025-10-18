# symfony-ai-agents
test symfony-ai platform and agents

### Installation

```shell
 composer require symfony/ai-platform:dev-main --dev
 composer require symfony/ai-agent:dev-main --dev
```
### Setup


 add in framework.yml:

```yaml
  http_client:
    default_options:
      max_redirects: 7
```

 run it with:

```shell
 bin/console boozt:finance-ai:query-wikipedia --profile
"explain SOLID principles"
 bin/console boozt:finance-ai:query-postgres --profile
"Which movie fits the theme of technology?"
"Find and list movies from the director who appears as director in more than one movie"
 bin/console boozt:finance-ai:query-atlasian --profile
"Search Jira issues assigned to user wojkaz@boozt.com"
```
