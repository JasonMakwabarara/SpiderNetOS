# SpiderNetOS MCP Server

Model Context Protocol server for SpiderNetOS - enables AI-native tools for codebase interaction.

## Installation

```bash
cd services/mcp-server
npm install
npm run build
```

## Usage

### With Claude Desktop

Add to your Claude Desktop configuration (`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS or `%APPDATA%/Claude/claude_desktop_config.json` on Windows):

```json
{
  "mcpServers": {
    "spidernetos": {
      "command": "node",
      "args": ["C:\\Users\\HP\\OneDrive - Swansea University\\Desktop\\Mabasa edu\\Apex\\SpiderNetOS\\services\\mcp-server\\dist\\server.js"]
    }
  }
}
```

### Available Tools

- **`code_search`** - Search codebase using regex patterns
- **`read_file`** - Read files with line offset/limit
- **`list_directory`** - List directory contents
- **`training_quality_gate`** - Run AI training data quality checks
- **`git_status`** - Get git status and recent commits
- **`list_components`** - List Vue components in cockpit

### Example Queries

"Search for all DataTable components"
"Run training data quality gate on latest bundle"
"Show me the git status and recent commits"
"List all Vue views in the cockpit"

## Development

```bash
npm run dev      # Watch mode
npm run build    # Compile TypeScript
npm run inspect  # Debug with MCP Inspector
```

## Architecture

- **Transport**: Stdio (for Claude Desktop integration)
- **Project Root**: Auto-detected relative to server location
- **Security**: Read-only operations, scoped to project directory
