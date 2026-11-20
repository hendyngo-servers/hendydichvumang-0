export async function generateWithOpenAI({ systemPrompt, input, config, fetchImpl = fetch }) {
  if (!config.OPENAI_API_KEY) throw new Error('MODEL_NOT_CONFIGURED');
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.OPENAI_TIMEOUT_MS);
  const base = config.OPENAI_BASE_URL.replace(/\/+$/, '');
  try {
    const response = await fetchImpl(`${base}/responses`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${config.OPENAI_API_KEY}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        model: config.OPENAI_MODEL,
        instructions: `${systemPrompt}\n\nQUY TẮC AN TOÀN HENDY: Chỉ tạo bản nháp dựa trên nội dung được cung cấp. Không tuyên bố đã duyệt web, gọi API, gửi Telegram, đăng bài, xử lý video thật, thanh toán, hoàn tiền hoặc thay đổi tài khoản. Không bịa nguồn, số liệu, giá hay trạng thái. Tất cả đầu ra cần Admin duyệt. Không yêu cầu mật khẩu, token hoặc khóa bí mật.`,
        input,
        max_output_tokens: 2200,
        store: false,
      }),
      signal: controller.signal,
    });
    if (!response.ok) {
      const status = response.status;
      throw new Error(`PROVIDER_HTTP_${status}`);
    }
    const data = await response.json();
    const content = data?.output_text || data?.output?.flatMap((item) =>
      item?.type === 'message' && Array.isArray(item.content)
        ? item.content.filter((part) => part?.type === 'output_text').map((part) => part.text || '')
        : [],
    ).join('\n');
    const output = typeof content === 'string' ? content.trim() : '';
    if (!output || output.length > 30000) throw new Error('PROVIDER_INVALID_OUTPUT');
    return {
      output,
      model: String(data.model || config.OPENAI_MODEL).slice(0, 100),
      inputTokens: Math.max(0, Number(data?.usage?.input_tokens || 0)),
      outputTokens: Math.max(0, Number(data?.usage?.output_tokens || 0)),
    };
  } catch (error) {
    if (error.name === 'AbortError') throw new Error('PROVIDER_TIMEOUT');
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}
