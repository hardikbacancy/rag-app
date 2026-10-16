import axios from 'axios';

const http = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } });

// Turn Laravel validation / server errors into one readable message.
export function errorMessage(error) {
    const data = error?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat().join(' ');
    return data?.message || error.message || 'Something went wrong';
}

export const api = {
    listDocuments: () => http.get('/documents').then((r) => r.data),
    uploadDocument: (form, onProgress) =>
        http.post('/documents', form, {
            onUploadProgress: (e) => e.total && onProgress?.(Math.round((e.loaded / e.total) * 100)),
        }).then((r) => r.data),
    reingest: (id, strategy) => http.post(`/documents/${id}/reingest`, { strategy }).then((r) => r.data),
    deleteDocument: (id) => http.delete(`/documents/${id}`),
    ask: (question, documentIds) => http.post('/ask', { question, document_ids: documentIds }).then((r) => r.data),
    pageImageUrl: (documentId, page) => `/api/documents/${documentId}/pages/${page}`,
    pdfUrl: (documentId, page) => `/api/documents/${documentId}/file#page=${page}`,
};

export const STRATEGIES = {
    baseline: 'Baseline – fixed-size chunks per page (plain narrative text)',
    hierarchical: 'Hierarchical – section-aware chunks with chapter/section paths (long structured reports)',
    multimodal: 'Multimodal – adds vision extraction of tables, charts and footnotes (visual reports)',
};
