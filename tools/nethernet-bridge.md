# NetherNet preview bridge

Minecraft Preview 1.26.60 uses NetherNet for this connection. LunaX's optional
`network.nethernet-bridge-port` setting accepts packet batches from the local
Python sidecar over framed TCP. The sidecar handles HTTP signaling and WebRTC.

1. Install Python 3.11 or newer and create a virtual environment.
2. Install the tested NetherNet implementation and its HTTP dependencies:

   ```powershell
   python -m venv .venv
   .\.venv\Scripts\python.exe -m pip install 'git+https://github.com/EndstoneMC/nethernet-python.git@a0e526ae5ee191c42745f8dce3425532947f4ec7#egg=nethernet[http]'
   ```

3. Set `network.nethernet-bridge-port: 19135` in `pocketmine.yml`, then start
   LunaX. Run `tools/nethernet-bridge.py` with the virtual environment's Python.
   The sidecar listens on TCP 19133 by default and connects to LunaX on
   `127.0.0.1:19135`. Override the ports with `LUNAX_NETHERNET_LISTEN_PORT` and
   `LUNAX_NETHERNET_BACKEND_PORT` if needed.

The sidecar creates `nethernet-operator-key.pem` in its working directory.
Keep this private key out of the repository. The current sidecar is for
1.26.60 preview testing and advertises protocol 2211.
