# Drupal Content Assistant

A Drupal 10/11 module that lets AI assistants (Claude and other MCP clients,
or Drupal AI chatbots and agents) **search, list, count and draft site
content** through four safe, permission-checked tools.

Ask in plain language:

- "List the 10 latest articles of journal X."
- "Which articles mention dengue since 2024?"
- "How many articles did each journal publish in 2025?"
- "Draft a news item about the new issue." (saved unpublished, for review)

The assistant acts as a Drupal user, sees only what that user can view, and
can never publish or run free-form queries.

See [the module README](modules/content_assistant/README.md) for features,
security, requirements, configuration and how to connect Claude.

| Module | Version | Requires |
|---|---|---|
| [Content Assistant](modules/content_assistant) | 1.0.0 | Drupal 10.5+/11, PHP 8.2+, [Tool](https://www.drupal.org/project/tool) 1.0 (beta). For MCP: [MCP Server](https://www.drupal.org/project/mcp_server) 2.x and [its Tool API bridge](https://www.drupal.org/project/mcp_server_tool_bridge), PHP 8.3. |

## License

GPL-2.0-or-later. Author: [Aluísio Ricardo Marques Pulga](https://www.drupal.org/u/aluisiopulga).
