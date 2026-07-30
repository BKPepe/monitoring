/**
 * WebSocket Live Stream klient pro real-time aktualizace z Go backendu (/api/v1/ws).
 */

export interface WSEventMessage {
  event: string;
  payload: any;
  timestamp: number;
}

export type WSEventCallback = (msg: WSEventMessage) => void;

export class WebSocketClient {
  private socket: WebSocket | null = null;
  private listeners: WSEventCallback[] = [];
  private reconnectTimer: number | null = null;
  private url: string;

  constructor(wsUrl?: string) {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const host = window.location.host;
    this.url = wsUrl || `${protocol}//${host}/api/v1/ws`;
  }

  public connect(): void {
    if (this.socket) return;

    try {
      this.socket = new WebSocket(this.url);

      this.socket.onmessage = (event) => {
        try {
          const msg: WSEventMessage = JSON.parse(event.data);
          this.notifyListeners(msg);
        } catch {
          // Ignorovat neplatné JSON zprávy
        }
      };

      this.socket.onclose = () => {
        this.socket = null;
        this.scheduleReconnect();
      };

      this.socket.onerror = () => {
        if (this.socket) {
          this.socket.close();
        }
      };
    } catch {
      this.scheduleReconnect();
    }
  }

  private scheduleReconnect(): void {
    if (this.reconnectTimer) return;
    this.reconnectTimer = window.setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, 5000);
  }

  public subscribe(callback: WSEventCallback): () => void {
    this.listeners.push(callback);
    return () => {
      this.listeners = this.listeners.filter((l) => l !== callback);
    };
  }

  private notifyListeners(msg: WSEventMessage): void {
    for (const listener of this.listeners) {
      listener(msg);
    }
  }

  public disconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.socket) {
      this.socket.close();
      this.socket = null;
    }
  }
}

export const wsClient = new WebSocketClient();
