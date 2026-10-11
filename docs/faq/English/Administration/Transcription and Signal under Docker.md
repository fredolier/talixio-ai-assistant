# Local transcription and Signal under Docker

## Transcription (whisper.cpp)
- The image contains whisper.cpp. The model is in the `talixio-whisper` volume, shared read-only
  by all assistants; `install-stack.sh` fills it (`--whisper-model large-v3-turbo-q5_0`
  by default, `small`, `medium`, or `none`). A model already installed on the server is copied.
- The large model needs about 1 GB while transcribing: give the person 2 GB
  (admin page → limits), or choose `small`.
- A one-hour meeting takes 30 to 60 minutes on 2 processors.

## Signal
signal-cli is in the image (x86_64 servers). Settings → Signal: the assistant's number and yours, then:
```bash
docker exec -it talixio-fred talixio signal register
docker exec -it talixio-fred talixio signal verify 123456
docker exec -it talixio-fred talixio signal updateProfile --given-name Talixio
docker restart talixio-fred
```

## If the image was built without them
If a download failed during the build, the image is made without that part and the build
log says so: run `sudo deploy/docker/install-stack.sh --rebuild-base` again.
