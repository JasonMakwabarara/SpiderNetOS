"""
Hermes Discord Bot Integration
Connects Discord to Hermes Agent for SpiderNetOS coordination
"""
import os
import asyncio
import logging
from typing import Optional
import aiohttp
import discord
from discord.ext import commands

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Configuration
DISCORD_TOKEN = os.getenv("DISCORD_BOT_TOKEN")
HERMES_API_URL = os.getenv("HERMES_API_URL", "http://127.0.0.1:8090")
SPIDERNET_API_URL = os.getenv("SPIDERNET_API_URL", "http://127.0.0.1:8000")

# Discord bot setup
intents = discord.Intents.default()
intents.message_content = True
intents.members = True
intents.guilds = True

bot = commands.Bot(
    command_prefix="!",
    intents=intents,
    description="Hermes - SpiderNetOS AI Communication Agent"
)


async def coordinate_with_hermes(message: str, user_id: str, channel_id: str, username: str) -> dict:
    """Send message to Hermes bridge for coordination."""
    try:
        async with aiohttp.ClientSession() as session:
            payload = {
                "message": message,
                "channel": "discord",
                "conversation_id": f"discord_{channel_id}_{user_id}",
                "user_context": {
                    "user_id": user_id,
                    "username": username,
                    "platform": "discord",
                    "channel_id": channel_id
                }
            }
            
            async with session.post(
                f"{HERMES_API_URL}/api/coordinate",
                json=payload,
                timeout=aiohttp.ClientTimeout(total=120)
            ) as response:
                if response.status == 200:
                    return await response.json()
                else:
                    logger.error(f"Hermes API error: {response.status}")
                    return {"response": "I'm having trouble processing that request. Please try again.", "status": "error"}
    except Exception as e:
        logger.error(f"Error coordinating with Hermes: {e}")
        return {"response": "Connection error. Please try again later.", "status": "error"}


@bot.event
async def on_ready():
    """Called when bot is ready."""
    logger.info(f"Hermes Discord Bot logged in as {bot.user.name} ({bot.user.id})")
    logger.info(f"Connected to {len(bot.guilds)} guilds")
    
    # Set bot status
    await bot.change_presence(
        activity=discord.Activity(
            type=discord.ActivityType.watching,
            name="SpiderNetOS | !help for commands"
        )
    )


@bot.event
async def on_message(message: discord.Message):
    """Handle incoming messages."""
    # Ignore bot's own messages
    if message.author == bot.user:
        return
    
    # Process commands first
    await bot.process_commands(message)
    
    # Handle DMs automatically
    if isinstance(message.channel, discord.DMChannel):
        await handle_direct_message(message)
        return
    
    # Handle mentions in guilds
    if bot.user.mentioned_in(message):
        await handle_mention(message)


async def handle_direct_message(message: discord.Message):
    """Handle DM conversations."""
    async with message.channel.typing():
        result = await coordinate_with_hermes(
            message.content,
            str(message.author.id),
            str(message.channel.id),
            message.author.name
        )
        
        # Send response
        response_text = result.get("response", "I'm not sure how to respond to that.")
        
        # Split long messages
        if len(response_text) > 2000:
            chunks = [response_text[i:i+1900] for i in range(0, len(response_text), 1900)]
            for chunk in chunks:
                await message.channel.send(chunk)
        else:
            await message.channel.send(response_text)


async def handle_mention(message: discord.Message):
    """Handle when bot is mentioned in a guild."""
    # Remove mention from message
    content = message.content.replace(f"<@{bot.user.id}>", "").replace(f"<@!{bot.user.id}>", "").strip()
    
    if not content:
        await message.reply("Hello! I'm Hermes, the SpiderNetOS AI communication agent. How can I help you?")
        return
    
    async with message.channel.typing():
        result = await coordinate_with_hermes(
            content,
            str(message.author.id),
            str(message.channel.id),
            message.author.name
        )
        
        response_text = result.get("response", "I'm not sure how to respond to that.")
        await message.reply(response_text)


# Bot Commands
@bot.command(name="help")
async def help_command(ctx: commands.Context):
    """Show help information."""
    embed = discord.Embed(
        title="Hermes - SpiderNetOS AI Agent",
        description="I coordinate with SpiderNetOS agents to help you with complex tasks.",
        color=discord.Color.blue()
    )
    
    embed.add_field(
        name="How to use",
        value="• DM me for private conversations\n• Mention me in channels\n• Use !commands for specific actions",
        inline=False
    )
    
    embed.add_field(
        name="Available Commands",
        value="`!help` - Show this message\n`!status` - Check Hermes status\n`!agents` - List available SpiderNetOS agents",
        inline=False
    )
    
    embed.add_field(
        name="Capabilities",
        value="• Multi-agent coordination\n• Workflow automation\n• Code generation\n• System monitoring\n• Learning & adaptation",
        inline=False
    )
    
    await ctx.send(embed=embed)


@bot.command(name="status")
async def status_command(ctx: commands.Context):
    """Check Hermes and SpiderNetOS status."""
    try:
        async with aiohttp.ClientSession() as session:
            # Check Hermes
            async with session.get(f"{HERMES_API_URL}/health", timeout=aiohttp.ClientTimeout(total=5)) as resp:
                hermes_status = "Online" if resp.status == 200 else "Offline"
            
            # Check SpiderNetOS
            async with session.get(f"{SPIDERNET_API_URL}/health", timeout=aiohttp.ClientTimeout(total=5)) as resp:
                spidernet_status = "Online" if resp.status == 200 else "Offline"
        
        embed = discord.Embed(title="Hermes System Status", color=discord.Color.green())
        embed.add_field(name="Hermes Bridge", value=hermes_status, inline=True)
        embed.add_field(name="SpiderNetOS", value=spidernet_status, inline=True)
        embed.add_field(name="Discord Bot", value="Online", inline=True)
        
        await ctx.send(embed=embed)
    except Exception as e:
        await ctx.send(f"Error checking status: {e}")


@bot.command(name="agents")
async def agents_command(ctx: commands.Context):
    """List available SpiderNetOS agents."""
    agents_info = """
**Atlas** - Code generation and software development
**Nexus** - Workflow orchestration and automation
**Prism** - Data analysis and visualization
**Sentinel** - Security and monitoring
**Forge** - Infrastructure and DevOps
**Hannah** - Customer support and communication
"""
    
    embed = discord.Embed(
        title="SpiderNetOS Agents",
        description="I can coordinate with these specialized agents:",
        color=discord.Color.purple()
    )
    embed.add_field(name="Available Agents", value=agents_info, inline=False)
    embed.set_footer(text="Mention me with your request and I'll route to the appropriate agent(s)")
    
    await ctx.send(embed=embed)


def main():
    """Start the Discord bot."""
    if not DISCORD_TOKEN:
        logger.error("DISCORD_BOT_TOKEN not set. Cannot start Discord bot.")
        logger.error("Set it with: $env:DISCORD_BOT_TOKEN='your_token'")
        return
    
    logger.info("Starting Hermes Discord Bot...")
    bot.run(DISCORD_TOKEN)


if __name__ == "__main__":
    main()
