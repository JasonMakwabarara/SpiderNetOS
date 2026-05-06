#!/usr/bin/env node

import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  Tool,
} from "@modelcontextprotocol/sdk/types.js";
import { execSync } from "child_process";
import { readFileSync, readdirSync, statSync, existsSync } from "fs";
import { join, relative, resolve } from "path";
import { glob } from "glob";
import { z } from "zod";

const PROJECT_ROOT = resolve(join(import.meta.dirname, "../.."));

// Tool definitions
const CODE_SEARCH_TOOL: Tool = {
  name: "code_search",
  description: "Search SpiderNetOS codebase using grep patterns",
  inputSchema: {
    type: "object",
    properties: {
      pattern: {
        type: "string",
        description: "Search pattern (regex supported)",
      },
      path: {
        type: "string",
        description: "Subdirectory to search (e.g., 'cockpit/src', 'backend/app')",
        default: ".",
      },
      filePattern: {
        type: "string",
        description: "File glob pattern (e.g., '*.vue', '*.php')",
        default: "*",
      },
    },
    required: ["pattern"],
  },
};

const READ_FILE_TOOL: Tool = {
  name: "read_file",
  description: "Read a file from the SpiderNetOS repository",
  inputSchema: {
    type: "object",
    properties: {
      path: {
        type: "string",
        description: "Relative path from project root",
      },
      offset: {
        type: "number",
        description: "Line number to start reading from",
        default: 1,
      },
      limit: {
        type: "number",
        description: "Maximum lines to read",
        default: 100,
      },
    },
    required: ["path"],
  },
};

const LIST_DIRECTORY_TOOL: Tool = {
  name: "list_directory",
  description: "List contents of a directory in the project",
  inputSchema: {
    type: "object",
    properties: {
      path: {
        type: "string",
        description: "Relative path from project root",
        default: ".",
      },
    },
    required: [],
  },
};

const TRAINING_GATE_TOOL: Tool = {
  name: "training_quality_gate",
  description: "Run AI training data quality gate checks",
  inputSchema: {
    type: "object",
    properties: {
      sftFile: {
        type: "string",
        description: "Path to SFT JSONL file",
        default: "training_data/sft.jsonl",
      },
      preferenceFile: {
        type: "string",
        description: "Path to preference JSONL file",
        default: "training_data/preference.jsonl",
      },
      minMeanQuality: {
        type: "number",
        description: "Minimum mean quality score",
        default: 7,
      },
    },
    required: [],
  },
};

const GIT_STATUS_TOOL: Tool = {
  name: "git_status",
  description: "Get git status and recent commits",
  inputSchema: {
    type: "object",
    properties: {
      commits: {
        type: "number",
        description: "Number of recent commits to show",
        default: 5,
      },
    },
    required: [],
  },
};

const LIST_COMPONENTS_TOOL: Tool = {
  name: "list_components",
  description: "List Vue components in the cockpit",
  inputSchema: {
    type: "object",
    properties: {
      type: {
        type: "string",
        description: "Component type filter (e.g., 'views', 'components')",
        default: "all",
      },
    },
    required: [],
  },
};

const server = new Server(
  {
    name: "spidernetos-mcp",
    version: "1.0.0",
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

// Tool implementations
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      CODE_SEARCH_TOOL,
      READ_FILE_TOOL,
      LIST_DIRECTORY_TOOL,
      TRAINING_GATE_TOOL,
      GIT_STATUS_TOOL,
      LIST_COMPONENTS_TOOL,
    ],
  };
});

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  try {
    switch (name) {
      case "code_search": {
        const { pattern, path = ".", filePattern = "*" } = args as {
          pattern: string;
          path?: string;
          filePattern?: string;
        };
        
        const searchPath = join(PROJECT_ROOT, path);
        const files = await glob(`**/${filePattern}`, {
          cwd: searchPath,
          absolute: true,
          nodir: true,
        });

        const results: Array<{ file: string; line: number; content: string }> = [];
        const regex = new RegExp(pattern, "i");

        for (const file of files.slice(0, 50)) { // Limit to 50 files
          try {
            const content = readFileSync(file, "utf-8");
            const lines = content.split("\n");
            lines.forEach((line, idx) => {
              if (regex.test(line)) {
                results.push({
                  file: relative(PROJECT_ROOT, file),
                  line: idx + 1,
                  content: line.trim(),
                });
              }
            });
          } catch {
            // Skip binary or unreadable files
          }
        }

        return {
          content: [
            {
              type: "text",
              text: `Found ${results.length} matches:\n\n${results
                .slice(0, 20)
                .map((r) => `${r.file}:${r.line}: ${r.content}`)
                .join("\n")}${results.length > 20 ? "\n\n... (truncated)" : ""}`,
            },
          ],
        };
      }

      case "read_file": {
        const { path, offset = 1, limit = 100 } = args as {
          path: string;
          offset?: number;
          limit?: number;
        };
        
        const filePath = join(PROJECT_ROOT, path);
        if (!existsSync(filePath)) {
          throw new Error(`File not found: ${path}`);
        }

        const content = readFileSync(filePath, "utf-8");
        const lines = content.split("\n");
        const start = Math.max(0, offset - 1);
        const end = Math.min(lines.length, start + limit);
        const selected = lines.slice(start, end);

        return {
          content: [
            {
              type: "text",
              text: `// ${path}:${start + 1}-${end}\n${selected.join("\n")}`,
            },
          ],
        };
      }

      case "list_directory": {
        const { path = "." } = args as { path?: string };
        const dirPath = join(PROJECT_ROOT, path);
        
        if (!existsSync(dirPath)) {
          throw new Error(`Directory not found: ${path}`);
        }

        const entries = readdirSync(dirPath, { withFileTypes: true });
        const items = entries.map((e) => ({
          name: e.name,
          type: e.isDirectory() ? "directory" : "file",
          size: e.isFile() ? statSync(join(dirPath, e.name)).size : null,
        }));

        return {
          content: [
            {
              type: "text",
              text: `Contents of ${path}:\n\n${items
                .map((i) => `${i.type === "directory" ? "📁" : "📄"} ${i.name}${i.size ? ` (${i.size} bytes)` : ""}`)
                .join("\n")}`,
            },
          ],
        };
      }

      case "training_quality_gate": {
        const {
          sftFile = "training_data/sft.jsonl",
          preferenceFile = "training_data/preference.jsonl",
          minMeanQuality = 7,
        } = args as {
          sftFile?: string;
          preferenceFile?: string;
          minMeanQuality?: number;
        };

        const scriptPath = join(PROJECT_ROOT, "scripts/eval_training_data_quality.py");
        const cmd = `python "${scriptPath}" --sft "${sftFile}" --preference "${preferenceFile}" --min-mean-quality ${minMeanQuality}`;
        
        try {
          const output = execSync(cmd, {
            cwd: PROJECT_ROOT,
            encoding: "utf-8",
            timeout: 30000,
          });
          return {
            content: [{ type: "text", text: output }],
          };
        } catch (error: any) {
          return {
            content: [
              { type: "text", text: `Quality gate failed:\n${error.stderr || error.message}` },
            ],
            isError: true,
          };
        }
      }

      case "git_status": {
        const { commits = 5 } = args as { commits?: number };
        
        const status = execSync("git status --short", {
          cwd: PROJECT_ROOT,
          encoding: "utf-8",
        });
        
        const recentCommits = execSync(`git log --oneline -n ${commits}`, {
          cwd: PROJECT_ROOT,
          encoding: "utf-8",
        });

        return {
          content: [
            {
              type: "text",
              text: `=== Git Status ===\n${status || "No changes"}\n\n=== Recent Commits ===\n${recentCommits}`,
            },
          ],
        };
      }

      case "list_components": {
        const { type = "all" } = args as { type?: string };
        
        let searchPath: string;
        if (type === "views") {
          searchPath = "cockpit/src/views";
        } else if (type === "components") {
          searchPath = "cockpit/src/components";
        } else {
          searchPath = "cockpit/src";
        }

        const fullPath = join(PROJECT_ROOT, searchPath);
        const files = await glob("**/*.vue", { cwd: fullPath, absolute: true });
        
        const components = files.map((f) => ({
          name: relative(fullPath, f).replace(/\\/g, "/"),
          path: relative(PROJECT_ROOT, f).replace(/\\/g, "/"),
        }));

        return {
          content: [
            {
              type: "text",
              text: `Found ${components.length} Vue components:\n\n${components
                .map((c) => `- ${c.name} (${c.path})`)
                .join("\n")}`,
            },
          ],
        };
      }

      default:
        throw new Error(`Unknown tool: ${name}`);
    }
  } catch (error: any) {
    return {
      content: [{ type: "text", text: `Error: ${error.message}` }],
      isError: true,
    };
  }
});

// Start server
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("SpiderNetOS MCP Server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
