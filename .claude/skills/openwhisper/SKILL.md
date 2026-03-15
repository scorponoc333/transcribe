---
name: whisper
description: Local speech-to-text transcription with the Whisper CLI (no API key required). Use when the user wants to transcribe or translate audio files.
metadata:
  tags: transcription, speech-to-text, audio, whisper
  requires_bin: whisper
---

# Whisper — Local Speech-to-Text

Transcribe audio files locally using the Whisper CLI. No API key, no cloud — everything runs on your machine.

## Install

Via Homebrew:
```bash
brew install openai-whisper
```

Or with pip:
```bash
pip install openai-whisper
```

## Usage

Transcribe audio to text:
```bash
whisper /path/audio.mp3 --model medium --output_format txt --output_dir .
```

Translate audio to English:
```bash
whisper /path/audio.m4a --task translate --output_format srt
```

## Output Formats

- `txt` — Plain text
- `srt` — Subtitles with timestamps
- `vtt` — WebVTT subtitles
- `json` — Structured JSON with word-level timestamps
- `tsv` — Tab-separated values

## Models

| Model | Size | Speed | Accuracy |
|-------|------|-------|----------|
| tiny | 39M | Fastest | Lower |
| base | 74M | Fast | Basic |
| small | 244M | Moderate | Good |
| medium | 769M | Slower | Better |
| large | 1.5G | Slowest | Best |
| turbo | ~800M | Fast | Good (default) |

Models download to `~/.cache/whisper` on first run.

## Notes

- `--model` defaults to `turbo` on Homebrew installs
- Use smaller models for speed, larger for accuracy
- Supports mp3, m4a, wav, flac, and most audio formats
- Runs entirely locally — no data leaves your machine
