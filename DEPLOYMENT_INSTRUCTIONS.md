## **Production Server Deployment Instructions**

Since I can't directly SSH into your production server due to tool limitations, please run this deployment script manually:

### **Step 1: Upload and Run Deployment Script**

**On your production server (5.223.68.233):**

```bash
# Download the deployment script
wget https://raw.githubusercontent.com/your-repo/spidernet-deploy/production-deploy.sh
# Or copy the script content above to a file called production-deploy.sh

# Make executable and run
chmod +x production-deploy.sh
./production-deploy.sh
```

### **Step 2: Verify Files Are in Place**

Before running the script, ensure these integration files are deployed to your production server:

**Required Files in `/var/www/spidernet/backend/`:**

1. `app/Http/Controllers/HermesController.php` ✅
2. `routes/api.php` (with hermes routes) ✅  
3. `app/Services/MemoryGraph.php` ✅
4. `app/Services/ReinforcementLearning.php` ✅
5. `app/Services/AgentMesh.php` ✅
6. Database migrations for new tables ✅

### **Step 3: Expected Output**

The deployment script will show:
```
🚀 Deploying SpiderNetOS + Hermes Integration
=============================================
📍 Working in: /var/www/spidernet/backend
💾 Creating backup...
✅ Backup created in backups/20260512_125000
📦 Installing dependencies...
🗄️ Running migrations...
⚙️ Clearing caches...
🔍 Checking Hermes integration...
✅ Hermes routes found
🌐 Starting server with external access...
✅ Server started with PID: 12345
🧪 Testing local access...
✅ Local API accessible
🔥 Configuring firewall...
✅ UFW configured

🎉 Deployment completed!
======================
Server PID: 12345
Local Access: http://localhost:8000
External Access: http://5.223.68.233:8000
```

### **Step 4: Test from Hermes Server**

**On your Hermes server (37.27.254.140):**

```bash
# Update environment
export SPIDERNET_API_URL="http://5.223.68.233:8000"
export HERMES_API_TOKEN="spidernet-secret-001"

# Run the integration test
python3 /opt/hermes-integrations/spidernet_integration.py
```

### **Step 5: Verify Integration**

You should see:
```
Testing SpiderNetOS Hermes integration...
✅ SpiderNet integration test passed
   Supported channels: 9
   Supported integrations: 7
```

## **If Issues Occur**

### **Files Missing:**
```bash
# On production server, check files
ls -la app/Http/Controllers/HermesController.php
php artisan route:list | grep hermes
```

### **Server Won't Start:**
```bash
# Check logs
tail -f storage/logs/server.log
tail -f storage/logs/laravel.log
```

### **Port Issues:**
```bash
# Check if port 8000 is in use
sudo netstat -tlnp | grep :8000

# Kill conflicting processes
sudo fuser -k 8000/tcp
```

### **Firewall Issues:**
```bash
# Check firewall status
sudo ufw status
sudo ufw allow 8000
```

## **Post-Deployment**

Once deployed and tested successfully:

1. **Monitor server logs:** `tail -f storage/logs/server.log`
2. **Set up process monitoring:** Configure systemd or supervisor
3. **Configure reverse proxy:** Set up nginx/apache for production
4. **SSL certificates:** Add HTTPS for security
5. **Load balancing:** Set up multiple instances if needed

The integration will then enable:
- ✅ Multi-channel communication (Discord, Slack, etc.)
- ✅ Autonomous agent orchestration  
- ✅ External webhook processing
- ✅ RL-powered continuous learning

**Run the deployment script on your production server and let me know the output!** 🎯