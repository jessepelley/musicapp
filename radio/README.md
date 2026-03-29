# Shuffle Radio — HomePod via Siri

Stream your music library as a 24/7 shuffled internet radio station, playable on HomePod via "Hey Siri, play [station name] on TuneIn".

## Architecture

```
Synology NAS                        TuneIn             HomePod
┌────────────────────┐         ┌──────────┐       ┌──────────┐
│ /volume1/music/*   │         │ Station  │       │ "Hey Siri│
│       │            │         │ Directory│       │  play my │
│  Liquidsoap        │         └────┬─────┘       │  radio"  │
│  (shuffle+encode)  │              │             └─────┬────┘
│       │            │              │                   │
│  Icecast2          │◄─────────────┘                   │
│  :8000/radio.mp3   │◄────────────────────────────────-┘
└────────────────────┘           direct stream
```

## Prerequisites

### Option A: Docker (Recommended for Synology)

Run both Icecast and Liquidsoap in Docker containers. Install Docker via Synology Package Center ("Container Manager").

```bash
# Pull images
docker pull savonet/liquidsoap:v2.3.1
docker pull libretime/icecast:2.4.4

# Or use docker-compose (see docker-compose.yml section below)
```

### Option B: Native Install

Install via Entware (if available on your NAS) or compile from source:

```bash
# Icecast
opkg install icecast

# Liquidsoap — may require building from source or using a pre-built binary
# See: https://www.liquidsoap.info/doc-dev/install.html
```

## Setup

### 1. Configure Passwords

Edit both config files and replace the placeholder passwords:

- `icecast.xml` — change `changeme_source_password`, `changeme_admin_password`, `changeme_relay_password`
- `radio.liq` — change `changeme_source_password` (must match icecast.xml's source-password)

### 2. Adjust Paths

In `radio.liq`:
- `music_dir` — path to your music library (default: `/volume1/music`)

In `icecast.xml`:
- `<logdir>`, `<basedir>`, `<webroot>` — adjust for your system

### 3. Start the Stream

```bash
./radio.sh start
```

Verify it's working:
```bash
./radio.sh status

# Or test directly:
curl -I http://localhost:8000/radio.mp3
# Should return: HTTP/1.0 200 OK, Content-Type: audio/mpeg
```

### 4. Set Up HTTPS (Required for TuneIn / HomePod)

HomePod and TuneIn require HTTPS. Use Synology's built-in reverse proxy:

1. **DNS**: Create an A record for `radio.jjjp.ca` pointing to your NAS public IP
2. **SSL Certificate**: In DSM, go to **Control Panel → Security → Certificate** and add a Let's Encrypt certificate for `radio.jjjp.ca`
3. **Reverse Proxy**: In DSM, go to **Control Panel → Login Portal → Advanced → Reverse Proxy** and create a rule:
   - **Source**: `https://radio.jjjp.ca:443`
   - **Destination**: `http://localhost:8000`
4. **Port Forwarding**: On your router, forward port 443 to your NAS (if not already done for music.jjjp.ca)

Test: `curl -I https://radio.jjjp.ca/radio.mp3`

### 5. Register on TuneIn

This is what makes "Hey Siri, play [name]" work on HomePod.

1. Go to [TuneIn Broadcasters](https://tunein.com/broadcasters/)
2. Click "Get started" / "Add a Station"
3. Fill in:
   - **Stream URL**: `https://radio.jjjp.ca/radio.mp3`
   - **Station Name**: Choose something unique and easy to say (e.g., "Jesse's Shuffle Radio")
   - **Genre**: Various / Mixed
   - **Language**: English
4. Submit and wait for approval (typically 1–3 business days)
5. Once approved, test on HomePod: **"Hey Siri, play Jesse's Shuffle Radio on TuneIn"**

### 6. Auto-Start on Boot

In Synology DSM:
1. Go to **Control Panel → Task Scheduler**
2. Create → Triggered Task → User-defined script
3. Event: **Boot-up**
4. User: **root**
5. Script: `/path/to/radio/radio.sh start`

## Docker Compose (Alternative Setup)

Create `docker-compose.yml` in this directory:

```yaml
version: "3.8"

services:
  icecast:
    image: libretime/icecast:2.4.4
    ports:
      - "8000:8000"
    volumes:
      - ./icecast.xml:/etc/icecast2/icecast.xml:ro
      - icecast-logs:/var/log/icecast2
    restart: unless-stopped

  liquidsoap:
    image: savonet/liquidsoap:v2.3.1
    depends_on:
      - icecast
    volumes:
      - ./radio.liq:/radio.liq:ro
      - /volume1/music:/music:ro
    command: liquidsoap /radio.liq
    restart: unless-stopped

volumes:
  icecast-logs:
```

If using Docker, update `radio.liq`:
- Change `music_dir` to `/music` (the mount point inside the container)
- Change `icecast_host` to `icecast` (Docker service name)

Then: `docker-compose up -d`

## Management

```bash
./radio.sh start     # Start Icecast + Liquidsoap
./radio.sh stop      # Stop both
./radio.sh restart   # Stop then start
./radio.sh status    # Check if running + stream health
```

## Stream URLs

| URL | Purpose |
|-----|---------|
| `http://localhost:8000/radio.mp3` | Direct stream (local) |
| `https://radio.jjjp.ca/radio.mp3` | Public stream (via reverse proxy) |
| `http://localhost:8000/status-json.xsl` | Icecast JSON status (current track, listeners) |
| `http://localhost:8000/admin/` | Icecast admin panel |

## Troubleshooting

**Stream not starting?**
- Check Icecast logs: `cat /var/log/icecast2/error.log`
- Check Liquidsoap logs: `cat /var/log/liquidsoap/radio.log`
- Ensure Icecast is running before Liquidsoap starts

**No audio / silence?**
- Verify music files exist: `ls /volume1/music/*.mp3`
- Liquidsoap may not support all formats natively — MP3, FLAC, OGG work best
- M4A/AAC may need additional codec packages depending on your Liquidsoap build

**HomePod can't connect?**
- Verify HTTPS is working: `curl -I https://radio.jjjp.ca/radio.mp3`
- Check that port 443 is forwarded to the NAS
- TuneIn may take time to propagate after approval

**"Siri can't find that station"?**
- TuneIn approval may still be pending
- Try the exact station name you registered
- Try: "Hey Siri, play [name] on TuneIn" (explicitly mentioning TuneIn helps)
