"""Bridge NetherNet WebRTC packet batches to LunaX over local framed TCP."""

from __future__ import annotations

import asyncio
import logging
import os
from pathlib import Path

import nethernet
from aiohttp import web
from cryptography.hazmat.primitives import serialization
from nethernet import IdentitySigner, SendType, generate_operator_key
from nethernet.signaling.http import HttpSignalingServer


KEY_PATH = Path(os.environ.get("LUNAX_NETHERNET_KEY", "nethernet-operator-key.pem"))
BACKEND_PORT = int(os.environ.get("LUNAX_NETHERNET_BACKEND_PORT", "19135"))
LISTEN_PORT = int(os.environ.get("LUNAX_NETHERNET_LISTEN_PORT", "19133"))


async def capability(self: HttpSignalingServer, request: web.Request) -> web.Response:
    logging.info("capability request from %s", request.remote)
    return web.json_response({
        "name": "LunaX Preview Probe",
        "protocol": 2211,
        "version": "1.26.60.25",
        "level": "world",
        "players": 0,
        "maxPlayers": 20,
        "gameType": 0,
    })


async def handle(connection: nethernet.Connection) -> None:
    logging.info("NetherNet connected: %s", connection.remote_id)
    reader, writer = await asyncio.open_connection("127.0.0.1", BACKEND_PORT)

    async def from_client() -> None:
        async for packet in connection:
            if not 0 < len(packet) <= 8 * 1024 * 1024:
                raise ValueError(f"invalid client batch length {len(packet)}")
            logging.info("client batch: %d bytes, prefix %s", len(packet), packet[:4].hex())
            writer.write(len(packet).to_bytes(4, "big") + packet)
            await writer.drain()

    async def from_server() -> None:
        while True:
            length = int.from_bytes(await reader.readexactly(4), "big")
            if not 0 < length <= 8 * 1024 * 1024:
                raise ValueError(f"invalid server batch length {length}")
            packet = await reader.readexactly(length)
            logging.info("server batch: %d bytes, prefix %s", length, packet[:4].hex())
            await connection.send(packet, SendType.RELIABLE)

    tasks = [asyncio.create_task(from_client()), asyncio.create_task(from_server())]
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in done:
            if error := task.exception():
                logging.warning("NetherNet bridge ended: %s", error)
        for task in pending:
            task.cancel()
        await asyncio.gather(*pending, return_exceptions=True)
    finally:
        writer.close()
        await writer.wait_closed()
        logging.info("NetherNet disconnected: %s", connection.remote_id)


def load_key():
    if KEY_PATH.exists():
        return serialization.load_pem_private_key(KEY_PATH.read_bytes(), password=None)
    key = generate_operator_key()
    KEY_PATH.write_bytes(key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ))
    return key


async def main() -> None:
    HttpSignalingServer._handle_capability = capability
    server = nethernet.serve_http(
        handle,
        host="0.0.0.0",
        port=LISTEN_PORT,
        identity_signer=IdentitySigner(load_key(), domain="localhost"),
    )
    async with server:
        logging.info("NetherNet HTTP signaling ready on TCP %d", server.bound_port)
        await server.serve_forever()


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    asyncio.run(main())
