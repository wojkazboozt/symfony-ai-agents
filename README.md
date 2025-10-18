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
 bin/console boozt:finance-ai:query-postgres --profile
 bin/console boozt:finance-ai:query-atlasian --profile
```
