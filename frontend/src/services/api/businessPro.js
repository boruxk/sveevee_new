import apiClient from '@/services/api/client'

const segment = value => encodeURIComponent(String(value))
export const fetchBusinessPro = (config = {}) => apiClient.get('/business-pro', config)
export const fetchPageBusinessPro = (pageId, config = {}) => apiClient.get(`/business-pro/pages/${segment(pageId)}`, config)
export const createBusinessProCheckout = (payload, config = {}) => apiClient.post('/business-pro/checkout', payload, config)
export const verifyBusinessProPayment = (id, config = {}) => apiClient.post(`/business-pro/payments/${segment(id)}/verify`, {}, config)
export const cancelBusinessPro = (config = {}) => apiClient.post('/business-pro/cancel', {}, config)
export const fetchAdminProSubscriptions = (params = {}, config = {}) => apiClient.get('/admin/business-pro/subscriptions', { ...config, params })
export const fetchAdminProPayments = (params = {}, config = {}) => apiClient.get('/admin/business-pro/payments', { ...config, params })
export const fetchAdminProFeatures = (config = {}) => apiClient.get('/admin/business-pro/features', config)
export const updateAdminProFeature = (id, payload, config = {}) => apiClient.patch(`/admin/business-pro/features/${segment(id)}`, payload, config)
export const fetchAdminProOffer = (config = {}) => apiClient.get('/admin/business-pro/offer', config)
export const updateAdminProOffer = (payload, config = {}) => apiClient.patch('/admin/business-pro/offer', payload, config)
