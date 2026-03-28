#!/usr/bin/liquidsoap
# ─── Shuffle Radio — Liquidsoap Configuration ────────────────────────────────
# Continuously shuffles and streams the music library via Icecast.
#
# Usage:
#   liquidsoap /path/to/radio.liq
#
# Requires: liquidsoap >= 2.0, icecast2 running on localhost:8000
# ──────────────────────────────────────────────────────────────────────────────

# ─── Settings ─────────────────────────────────────────────────────────────────

# Path to the music library (same directory the web app uses)
music_dir = "/volume1/music"

# Icecast connection
icecast_host     = "localhost"
icecast_port     = 8000
icecast_password = "changeme_source_password"
icecast_mount    = "/radio.mp3"

# Stream settings
bitrate   = 192                # kbps — good quality, wide device compatibility
samplerate = 44100

# Crossfade duration in seconds (smooth transitions between tracks)
crossfade_duration = 3.0

# How often to re-scan the music directory for new files (seconds)
reload_interval = 3600         # 1 hour

# ─── Logging ──────────────────────────────────────────────────────────────────

settings.log.file.set(true)
settings.log.file.path.set("/var/log/liquidsoap/radio.log")
settings.log.level.set(3)     # 1=critical, 2=severe, 3=important, 4=info, 5=debug

# ─── Source: Shuffled Music Library ───────────────────────────────────────────

# playlist() recursively scans the directory for audio files.
# mode="randomize" shuffles the entire playlist, then reshuffles when exhausted.
# reload_mode="watch" re-scans when files change; reload=3600 is a fallback.
radio = playlist(
  music_dir,
  mode="randomize",
  reload=reload_interval,
  reload_mode="watch",
  mime_type=""
)

# ─── ReplayGain Normalization ─────────────────────────────────────────────────
# Match the web app's volume normalization behavior.
# amplify() reads REPLAYGAIN_TRACK_GAIN metadata from each file.

radio = amplify(
  override="replaygain_track_gain",
  1.0,
  radio
)

# ─── Crossfade ────────────────────────────────────────────────────────────────
# Smooth transitions between tracks (avoids abrupt cuts).

radio = crossfade(
  duration=crossfade_duration,
  radio
)

# ─── Fallback: Silence if source fails ────────────────────────────────────────
# If the playlist is empty or fails, output silence rather than crashing.

safety = blank(duration=1.0)
radio  = fallback(track_sensitive=false, [radio, safety])

# ─── Output to Icecast ────────────────────────────────────────────────────────

output.icecast(
  %mp3(
    bitrate=bitrate,
    samplerate=samplerate,
    stereo=true
  ),
  host=icecast_host,
  port=icecast_port,
  password=icecast_password,
  mount=icecast_mount,
  name="Shuffle Radio",
  description="Shuffled music library radio",
  genre="Various",
  url="https://music.jjjp.ca",
  public=false,               # Don't list on Icecast directory (TuneIn handles discovery)
  radio
)
