import { defineConfig } from '@neon/config/v1';
export default defineConfig({
  buckets: { uploads: {} },
  functions: {
    hendystorage: {
      name: 'HENDY private upload gateway',
      source: 'neon-functions/hendystorage/index.mjs',
      env: {
        HENDY_FUNCTION_API_KEY: {
          value: process.env.HENDY_FUNCTION_API_KEY,
          required: true,
        },
      },
    },
  },
  preview: { realtime: true },
});
