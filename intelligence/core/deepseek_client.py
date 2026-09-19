"""
SpiderNet OS - DeepSeek v4 Client
Unified interface for DeepSeek reasoning and code generation
"""

import json
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, Optional


@dataclass
class DeepSeekResponse:
    """Structured response from DeepSeek"""
    content: str
    reasoning: Optional[str] = None
    model: str = "deepseek-v4"
    tokens_used: int = 0
    format_valid: bool = True


class DeepSeekClient:
    """
    Client for DeepSeek v4 via Ollama.

    Provides:
    - Strategic reasoning for MetaPlanner
    - Code generation for agent builders
    - JSON-structured outputs
    """

    def __init__(
        self,
        ollama_url: str = "http://localhost:11434",
        model: str = "deepseek-v4",
        timeout: int = 300
    ):
        self.ollama_url = ollama_url
        self.model = model
        self.timeout = timeout

        # Verify model availability
        self._check_model()

    def _check_model(self):
        """Verify DeepSeek model is available"""
        try:
            req = urllib.request.Request(
                f"{self.ollama_url}/api/tags",
                method="GET"
            )
            with urllib.request.urlopen(req, timeout=10) as resp:
                data = json.loads(resp.read().decode())
                models = [m["name"] for m in data.get("models", [])]

                if self.model not in models:
                    # Try fallback models
                    fallbacks = ["deepseek-coder:33b", "deepseek-coder", "qwen3.6:latest"]
                    for fb in fallbacks:
                        if fb in models:
                            print(f"[DeepSeek] {self.model} not found, using {fb}")
                            self.model = fb
                            return

                    raise ValueError(f"No suitable model found. Available: {models}")

                print(f"[DeepSeek] Using model: {self.model}")

        except Exception as e:
            print(f"[DeepSeek] Warning: Could not verify model: {e}")

    def generate(
        self,
        prompt: str,
        system_prompt: Optional[str] = None,
        temperature: float = 0.1,
        max_tokens: int = 4000,
        expect_json: bool = False,
        expect_code: bool = False
    ) -> DeepSeekResponse:
        """
        Generate response from DeepSeek.

        Args:
            prompt: User prompt
            system_prompt: System context
            temperature: 0.0-1.0 (lower = more deterministic)
            max_tokens: Maximum tokens to generate
            expect_json: Parse and validate JSON output
            expect_code: Clean code blocks from output
        """

        # Build full prompt with system context
        if system_prompt:
            full_prompt = f"{system_prompt}\n\n{prompt}"
        else:
            full_prompt = prompt

        # Add format instructions
        if expect_json:
            full_prompt += "\n\nRespond ONLY with valid JSON. No markdown, no explanations."
        elif expect_code:
            full_prompt += "\n\nGenerate ONLY clean code. No markdown fences, no explanations."

        # Call Ollama
        data = json.dumps({
            "model": self.model,
            "prompt": full_prompt,
            "stream": False,
            "options": {
                "temperature": temperature,
                "num_predict": max_tokens,
                "top_p": 0.9,
                "top_k": 40
            }
        }).encode()

        req = urllib.request.Request(
            f"{self.ollama_url}/api/generate",
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST"
        )

        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                result = json.loads(resp.read().decode())
                raw_content = result.get("response", "")

                # Process based on expected format
                if expect_json:
                    content = self._extract_json(raw_content)
                elif expect_code:
                    content = self._extract_code(raw_content)
                else:
                    content = raw_content.strip()

                return DeepSeekResponse(
                    content=content,
                    model=self.model,
                    tokens_used=result.get("eval_count", 0),
                    format_valid=True
                )

        except urllib.error.URLError as e:
            return DeepSeekResponse(
                content="",
                model=self.model,
                format_valid=False,
                reasoning=f"Connection error: {e}"
            )
        except Exception as e:
            return DeepSeekResponse(
                content="",
                model=self.model,
                format_valid=False,
                reasoning=f"Error: {e}"
            )

    def _extract_json(self, text: str) -> str:
        """Extract JSON from response text"""
        # Remove markdown fences
        lines = text.split("\n")
        start_idx = 0
        end_idx = len(lines)

        for i, line in enumerate(lines):
            if line.strip().startswith("```"):
                start_idx = i + 1
                break

        for i in range(len(lines) - 1, -1, -1):
            if lines[i].strip().startswith("```"):
                end_idx = i
                break

        json_text = "\n".join(lines[start_idx:end_idx]).strip()

        # Validate JSON
        try:
            json.loads(json_text)
            return json_text
        except json.JSONDecodeError:
            # Return raw if can't parse
            return text.strip()

    def _extract_code(self, text: str) -> str:
        """Extract clean code from response"""
        lines = text.split("\n")
        start_idx = 0
        end_idx = len(lines)

        # Find code block
        for i, line in enumerate(lines):
            if line.strip().startswith("```"):
                start_idx = i + 1
                break

        for i in range(len(lines) - 1, start_idx - 1, -1):
            if lines[i].strip().startswith("```"):
                end_idx = i
                break

        code = "\n".join(lines[start_idx:end_idx]).strip()

        # Remove common preamble phrases
        preambles = [
            "here is", "here's", "below is", "i have created",
            "the code", "generated", "sure", "okay", "this is"
        ]

        lower_code = code.lower()
        for preamble in preambles:
            if lower_code.startswith(preamble):
                # Find first real code line
                for i, line in enumerate(code.split("\n")):
                    stripped = line.strip()
                    if stripped and not stripped.lower().startswith(preamble):
                        code = "\n".join(code.split("\n")[i:])
                        break
                break

        return code

    # Specialized methods for different use cases

    def plan_execution(
        self,
        command: str,
        context: Dict[str, Any]
    ) -> Dict:
        """
        Strategic planning for MetaPlanner.
        Returns structured execution plan.
        """

        system_prompt = """You are the SpiderNetOS MetaPlanner's strategic reasoning engine.
Analyze commands and determine optimal execution strategies.
Be concise, deterministic, and economically-minded."""

        prompt = f"""
COMMAND: {command}

CONTEXT:
- Budget remaining: ${context.get('budget', 0):.2f}
- Cost ceiling: ${context.get('cost_ceiling', 50):.2f}
- Active agents: {context.get('active_agents', [])}
- Current system load: {context.get('load', 0)}%
- Tenant tier: {context.get('tier', 'basic')}
- Time constraints: {context.get('time_constraint', 'none')}

AVAILABLE AGENTS:
- atlas: Natural language parsing, command routing
- forge: Agent/workflow creation and DAG building
- nexus: Infrastructure and deployment management
- sentinel: Security, monitoring, compliance
- prism: Data analysis, insights, reporting

DECIDE:
1. Which agents should execute this command?
2. In what order should they run?
3. What are the main risks?
4. What's the estimated cost?
5. Should this be executed now or queued?

RESPOND WITH JSON:
{{
    "agents": ["agent1", "agent2"],
    "execution_order": [0, 1],
    "risks": ["risk description"],
    "estimated_cost": 12.50,
    "execution_decision": "execute_now|queue|reject",
    "rationale": "Brief explanation"
}}"""

        response = self.generate(
            prompt=prompt,
            system_prompt=system_prompt,
            temperature=0.05,  # Very deterministic
            expect_json=True
        )

        try:
            return json.loads(response.content)
        except json.JSONDecodeError:
            return {
                "agents": ["atlas"],
                "execution_order": [0],
                "risks": ["JSON parse error, falling back to atlas"],
                "estimated_cost": 5.0,
                "execution_decision": "execute_now",
                "rationale": response.content[:200]
            }

    def generate_vue_component(
        self,
        component_name: str,
        requirements: str,
        theme: str = "dark"
    ) -> str:
        """
        Generate Vue 3 component code.
        Specialized for SpiderNetOS cockpit UI.
        """

        system_prompt = """You are the SpiderNetOS Code Architect.
Generate production-ready Vue 3 components using Composition API (<script setup>).
Use Tailwind CSS. Ensure every HTML tag has matching closing tag."""

        color_scheme = {
            "dark": "Background #0A0A0F, cards #1A1A24, primary #FF6B2C, text #E8E8EF",
            "light": "Background #FFFFFF, cards #F5F5F5, primary #FF6B2C, text #1A1A24"
        }.get(theme, "dark")

        prompt = f"""
Create a Vue 3 component named "{component_name}".

REQUIREMENTS:
{requirements}

DESIGN SYSTEM:
- {color_scheme}
- Use Tailwind CSS utility classes
- Vue 3 Composition API with <script setup>
- Dark theme UI
- Responsive design
- No external libraries (no charts, use CSS)

CRITICAL RULES:
1. EVERY HTML tag must have matching closing tag
2. Use self-closing only for: img, br, input
3. No unclosed divs, spans, or sections
4. Valid Vue 3 syntax
5. Include comments for complex logic

Generate the complete .vue file content:"""

        response = self.generate(
            prompt=prompt,
            system_prompt=system_prompt,
            temperature=0.05,
            expect_code=True,
            max_tokens=4000
        )

        return response.content

    def analyze_code_quality(self, code: str, language: str = "vue") -> Dict:
        """Analyze code for issues and improvements"""

        prompt = f"""
Analyze this {language} code for:
1. Syntax errors
2. Missing closing tags
3. Logic issues
4. Performance concerns
5. Best practice violations

CODE:
```{language}
{code}
```

Respond with JSON:
{{
    "valid": true|false,
    "issues": ["issue description"],
    "suggestions": ["improvement suggestion"],
    "confidence": 0.0-1.0
}}"""

        response = self.generate(
            prompt=prompt,
            temperature=0.1,
            expect_json=True
        )

        try:
            return json.loads(response.content)
        except:
            return {
                "valid": False,
                "issues": ["Could not parse analysis"],
                "suggestions": [],
                "confidence": 0.0
            }


# Singleton instance for global use
deepseek_client: Optional[DeepSeekClient] = None


def get_deepseek_client() -> DeepSeekClient:
    """Get or create global DeepSeek client"""
    global deepseek_client
    if deepseek_client is None:
        deepseek_client = DeepSeekClient()
    return deepseek_client
