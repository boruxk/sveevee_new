export function isGuestChatLinkError(error, field = 'body') {
	const errors = error.response?.data?.errors?.[field]
	return error.response?.status === 422 && [errors].flat().includes('guest_chat_links_not_allowed')
}
