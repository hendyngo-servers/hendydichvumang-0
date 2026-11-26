declare module '@neon/config/v1' {
  export function defineConfig<T>(config: T): T;
}
declare const process: { env: Record<string, string | undefined> };
