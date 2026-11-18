# Voice notes, meetings and transcription

- **Voice note**: the microphone in the app, or a voice message on Telegram/Signal. It is transcribed, then handled.
- **Meeting**: microphone → *Summarise meeting* (keep the page open and the phone awake), or
  upload a recording (any audio longer than 3 minutes counts as a meeting).
  You get a summary, the decisions, the action items (who/what/when) and the open questions,
  with an offer to create the reminders and tasks.
- Transcripts and summaries are searchable afterwards. The minutes are also saved as a note, next to the recording.
- Telegram limits files to 20 MB: upload long recordings in the web app.

## Long meetings, and when the recording is interrupted
- **Saved as you go**: while you record, the app sends the audio to the server every 5 seconds (the bar says
  *saved as you go*). If the tab closes, the battery dies or the connection drops, at most the last few seconds are lost.
- **Interrupted?** The next time you open the app, a bar above the message box shows *An interrupted recording was saved*,
  with its length: **Summarise meeting**, **Send as voice note**, or **Discard**. It is kept for 7 days.
- **Connection lost while recording**: the bar says so and the recording is kept on your device; keep the page open,
  it catches up when the connection is back. If it never comes back, the whole recording is uploaded when you stop.
- **No length limit in practice**: long recordings are transcribed in pieces (40 minutes with Gemini, 20 minutes with
  OpenAI and the European providers), then joined, with the times following on. Speaker names are guessed in each piece:
  in a long meeting with unnamed voices, "Speaker 1" in one piece may be "Speaker 2" in the next.
- A very long transcript (beyond about 5 hours of speech) is summarised from its first part; the whole transcript is still saved
  and searchable, and the summary says where it was cut.

## Why a separate transcription?
Claude does not take audio as input: transcription is a separate step.

## Transcription options (Settings → Model and budget → Models → the *Voice* card)
Pick the model with the **V** button of a row in the table: Whisper or Voxtral at a European provider, OpenAI, Gemini, or
**Whisper (local)** on this server. The *Voice* card also holds the meeting settings: the tier for the summaries and the
length from which a recording counts as a meeting. The options:
- **Gemini**: about $0.15 per hour of recording, with speaker names. With a
  free key, Google may use the data to train its models; with billing
  turned on, it does not. Say so in Settings ("My Gemini key is on a paid plan").
- **OpenAI**: gpt-4o-transcribe.
- **Local (whisper.cpp)**: the audio never leaves the server, and it is free, but slower (about
  half the length of the recording on 4 cores) and without speaker names.
- **European providers** (OVHcloud, Scaleway, Infomaniak: Whisper; Mistral: Voxtral): the audio stays in Europe.
- **No model chosen**: automatic. The European provider first, when it is your main provider or the **Europe lock (GDPR)**
  is on (with the lock on and no European provider ready, only local is used); otherwise Gemini if a key is set; then
  OpenAI if it is your provider; then local if whisper.cpp is installed; otherwise no transcription.
- In a **Confidential** space, transcription is always done locally.

## Which Gemini model? (Settings → Model and budget → Models → the **V** button)
The model is the row you pick with the **V** button; with none chosen, the choice is automatic, which is the right setting in most cases.

**Gemini** (the name starts with `gemini`):

| Model | What for |
|---|---|
| `gemini-2.5-flash` | The default choice. Good quality, speaker names, cheap. |
| `gemini-2.5-flash-lite` | The cheapest. Enough for voice notes; a little less reliable on long meetings with several voices. |
| `gemini-2.5-pro` | More expensive and slower, rarely better for a transcription. |
| `gemini-3-flash-preview`, … | Newer models available with your key work too (the list is in Settings → Model and budget). |

With Gemini but no Gemini model chosen: Gemini's "standard" model if Gemini is your provider, otherwise `gemini-2.5-flash`.

**Local (whisper.cpp)**: the name of a `ggml-<name>.bin` file in `/opt/whisper.cpp/models`, or its path.

| Model | Size | Quality in French | Speed |
|---|---|---|---|
| `large-v3-turbo-q5_0` | 0.55 GB | Very good | The default choice, the best trade-off |
| `large-v3-turbo` | 1.6 GB | Very good | Same as above, more memory |
| `large-v3` | 3.1 GB | The best | About 4 times slower than turbo |
| `medium` (`medium-q5_0`: 0.5 GB) | 1.5 GB | Good | Slower than turbo |
| `small` (`small-q5_1`: 0.2 GB) | 0.5 GB | Fair (proper names and numbers less reliable) | Fast |
| `base`, `tiny` | 0.15 / 0.08 GB | Poor in French | Very fast |

The model must be downloaded to the server: `sudo ./install.sh --with-whisper` (variable
`WHISPER_MODEL=small` to choose another one), or under Docker
`install-stack.sh --whisper-model small`. The files come from
huggingface.co/ggerganov/whisper.cpp. Plan for about twice the model's size in memory.
