export class ApiClient {
  constructor(onRequest = () => {}) { this.csrf = ''; this.onRequest = onRequest; }
  async request(method, path, payload, extraHeaders = {}) {
    const url = '/api/demo/v1' + path;
    const headers = {...extraHeaders};
    if (payload !== undefined) headers['Content-Type'] = 'application/json';
    if (['POST','PATCH','DELETE'].includes(method)) headers['X-CSRF-Token'] = this.csrf;
    const started = performance.now();
    let response, body;
    try {
      response = await fetch(url, {method, headers, credentials:'same-origin', body:payload === undefined ? undefined : JSON.stringify(payload)});
      this.csrf = response.headers.get('X-CSRF-Token') || this.csrf;
      body = await response.json();
    } catch (error) {
      this.onRequest({method,url,payload:payload??null,status:response?.status??0,response:{error:{code:'network_error',message:'Could not read the API response.'}},duration:performance.now()-started});
      throw new Error('Connection failed. Please try again.');
    }
    this.onRequest({method,url,payload:payload??null,status:response.status,response:body,duration:performance.now()-started});
    if (!response.ok) { const error = new Error(body.error?.message || 'The request failed.'); error.status=response.status; error.code=body.error?.code; throw error; }
    return body;
  }
}
