#!/usr/bin/env python3
"""AI SSH Authentication Fix Agent"""
import subprocess
import os
import json

OLLAMA_URL = "http://localhost:11434"

def diagnose_ssh_issue():
    """Diagnose SSH connection failure"""
    print("=" * 60)
    print("AI SSH AUTHENTICATION DIAGNOSTICS")
    print("=" * 60)
    
    # Check for existing SSH keys
    ssh_dir = os.path.expanduser("~/.ssh")
    existing_keys = []
    if os.path.exists(ssh_dir):
        for f in os.listdir(ssh_dir):
            if f.endswith(('.pem', '.key', '_rsa', '_ed25519')):
                existing_keys.append(f)
    
    print(f"\n[LOCAL] Existing SSH keys: {existing_keys or 'None found'}")
    
    # Vast.ai specific info
    vast_info = {
        "ip": "220.134.41.156",
        "port": "18239",
        "user": "root",
        "error": "Permission denied (publickey)",
        "solution_options": [
            "1. Download SSH key from Vast.ai dashboard (Instance > SSH Keys)",
            "2. Use the downloaded .pem file with: ssh -i key.pem -L 5173:localhost:5173 -p 18239 root@220.134.41.156",
            "3. Alternative: Use Vast.ai's 'Connect' button for auto-generated command"
        ]
    }
    
    print("\n[VAST.AI] Connection Details:")
    for k, v in vast_info.items():
        if k != "solution_options":
            print(f"  {k}: {v}")
    
    print("\n[AI RECOMMENDATION]")
    print("=" * 60)
    print("The SSH connection requires key-based authentication.")
    print("\nOption 1 (Easiest): Use Vast.ai Web Terminal")
    print("  → Go to https://cloud.vast.ai/instances")
    print("  → Click 'Instance Portal' or 'Jupyter Terminal'")
    print("  → No SSH needed, runs in browser")
    
    print("\nOption 2 (SSH Tunnel): Download SSH Key")
    print("  → In Vast.ai dashboard, find your instance")
    print("  → Click 'SSH Keys' or 'Connect'")
    print("  → Download the .pem file")
    print("  → Save to C:\\Users\\HP\\.ssh\\vast_key.pem")
    print("  → Run: ssh -i ~/.ssh/vast_key.pem -L 5173:localhost:5173 -p 18239 root@220.134.41.156")
    
    print("\nOption 3 (Browser Access): Use Cloudflare Tunnel")
    print("  → If configured, use the provided HTTPS URL")
    print("  → Check Vast.ai dashboard for 'Tunnels' section")
    
    return vast_info

def generate_windows_ssh_command():
    """Generate Windows-compatible SSH command"""
    print("\n" + "=" * 60)
    print("WINDOWS SSH COMMAND GENERATOR")
    print("=" * 60)
    
    key_path = input("Enter path to your Vast.ai SSH key (or press Enter for default): ").strip()
    if not key_path:
        key_path = "%USERPROFILE%\\.ssh\\vast_key.pem"
    
    # Generate commands
    commands = {
        "test_connection": f"ssh -i {key_path} -p 18239 root@220.134.41.156 -o StrictHostKeyChecking=yes",
        "tunnel_cockpit": f"ssh -i {key_path} -L 5173:localhost:5173 -p 18239 root@220.134.41.156",
        "tunnel_all": f"ssh -i {key_path} -L 5173:localhost:5173 -L 8001:localhost:8001 -L 9000:localhost:9000 -p 18239 root@220.134.41.156"
    }
    
    print("\n[GENERATED COMMANDS]")
    for name, cmd in commands.items():
        print(f"\n{name}:")
        print(f"  {cmd}")
    
    # Save to batch file
    batch_content = f"""@echo off
echo SpiderNetOS SSH Tunnel
echo ======================
echo 1. Test Connection
echo 2. Tunnel Cockpit Only
echo 3. Tunnel All Services
echo.
set /p choice="Select option (1-3): "

if "%choice%"=="1" (
    {commands['test_connection']}
) else if "%choice%"=="2" (
    echo Starting Cockpit tunnel...
    echo Access: http://localhost:5173
    {commands['tunnel_cockpit']}
) else if "%choice%"=="3" (
    echo Starting all tunnels...
    echo Cockpit: http://localhost:5173
    echo Atlas API: http://localhost:8001
    echo Inference: http://localhost:9000
    {commands['tunnel_all']}
)
pause
"""
    
    batch_path = os.path.expanduser("~/spidernet-tunnel.bat")
    with open(batch_path, 'w') as f:
        f.write(batch_content)
    
    print(f"\n[Saved] Batch file created: {batch_path}")
    print("Double-click to run interactive SSH tunnel selector")

if __name__ == "__main__":
    info = diagnose_ssh_issue()
    
    response = input("\nDo you have the Vast.ai SSH key file? (yes/no): ").lower()
    if response in ['yes', 'y']:
        generate_windows_ssh_command()
    else:
        print("\n[ALTERNATIVE] Use Vast.ai Web Terminal instead:")
        print("  https://cloud.vast.ai/instances")
        print("  → Click 'Instance Portal' for browser-based access")
