# Content Assistant

Lets AI assistants work with your Drupal content through four safe,
permission-checked tools:

| Tool | Operation | What it does |
|---|---|---|
| `content_assistant_collections` | read | Lists the content collections the assistant can use, their groups (for example journal names) and whether drafts are allowed. |
| `content_assistant_search` | read | Finds items, newest first, by keywords, group and date range. With no keywords it lists the latest items: "the 10 latest articles of journal X". |
| `content_assistant_report` | read | Counts items per group or per year: "how many articles per journal in 2024?". |
| `content_assistant_create_draft` | write | Creates an **unpublished** draft (for example a news item) for a person to review and publish. |

The tools are [Tool API](https://www.drupal.org/project/tool) plugins, so the
same four tools work with:

- **Claude, Cursor and other MCP clients**, through
  [MCP Server](https://www.drupal.org/project/mcp_server) and its
  [Tool API bridge](https://www.drupal.org/project/mcp_server_tool_bridge).
  The module ships the MCP mappings; they are installed when the bridge is.
- **The Drupal AI module** (chatbots and agents), through the Tool module's
  experimental *Tool - AI Connector* submodule.
- **Drush**: `drush tool:list`, `drush tool:run`.

## How it stays safe

- The assistant acts as a Drupal user and only sees what that user can view:
  entity queries are access checked, unpublished content is hidden from users
  who cannot see it, and every item is checked again before it is returned.
- Only the content types you add as collections are visible, and only titles,
  dates, groups and links are returned, not the full text.
- Drafts are always unpublished, only in collections where you allowed them,
  and only for users with the "Create drafts with the content assistant"
  permission **and** permission to create that content type. Scripts and event
  attributes are stripped from the text, and every draft is logged.
- There is no free-form database access: each tool runs a fixed query.

## Requirements

| | |
|---|---|
| Drupal | 10.5 or later, or 11 |
| PHP | 8.2 or later (8.3 for MCP Server) |
| Modules | Node, Filter (core) and [Tool](https://www.drupal.org/project/tool) 1.0.0-beta12 or later |
| For MCP clients | [MCP Server](https://www.drupal.org/project/mcp_server) 2.x and [MCP Server Tool Bridge](https://www.drupal.org/project/mcp_server_tool_bridge) |
| For the Drupal AI module | [AI](https://www.drupal.org/project/ai) and the Tool module's *Tool - AI Connector* submodule |

Tool, MCP Server and the bridge are beta software: their APIs may still change.

## Installation

```bash
composer require drupal/tool:^1.0@beta
# Copy this module to modules/custom/content_assistant, then:
drush en content_assistant
```

For MCP clients:

```bash
composer require drupal/mcp_server:^2.0@beta drupal/mcp_server_tool_bridge:^1.0@beta
drush en mcp_server_tool_bridge
```

## Configuration

1. Go to **Configuration > Web services > Content assistant**
   (`/admin/config/services/content-assistant`).
2. Add a collection: a label, a machine name and its content type. Save.
3. Choose its fields:
   - **Date field**: orders results and powers date filters (Authored on, or a
     date field such as a publication date).
   - **Group field** (optional): the journal, category or department, as a
     reference, list or text field.
   - **Searchable text fields**: keyword searches look in the title and these.
   - **Allow drafts** and the **body field**, for content the assistant may
     draft, such as news.
   - A short **description**, which tells the assistant what the content is.
4. Grant **Use content assistant tools** (and, for drafts, **Create drafts with
   the content assistant**) to the role of the account the assistant will use.
   A dedicated account with only these roles is best.

## Connecting Claude (MCP)

MCP Server's STDIO transport runs through Drush, as the Drupal user you name.
On the machine where the site runs, add this to the Claude Desktop
configuration (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "my-site": {
      "command": "vendor/bin/drush",
      "args": ["mcp:server", "assistant"],
      "cwd": "/path/to/drupal"
    }
  }
}
```

For a remote server, run the same command over SSH:

```json
{
  "mcpServers": {
    "my-site": {
      "command": "ssh",
      "args": ["user@server", "cd /path/to/drupal && vendor/bin/drush mcp:server assistant"]
    }
  }
}
```

`assistant` is the Drupal account the tools run as. Then ask, for example:
"List the 10 latest articles of journal X", "How many articles did each
journal publish in 2025?" or "Draft a news item about ...".

## Tests

Kernel tests cover the tools end to end through the Tool API runner,
permissions, unpublished content, draft safety, the settings form and, when the
bridge is installed, the MCP exposure.

```bash
cd web
SIMPLETEST_DB=sqlite://localhost/sites/simpletest/db.sqlite \
  ../vendor/bin/phpunit -c core modules/custom/content_assistant
```

## License

GPL-2.0-or-later.
