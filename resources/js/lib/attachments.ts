/**
 * How a message attachment is shown, following the support app (tx-chatbot).
 *
 * Photos a client texts in arrive as Twilio links with no file extension, so
 * an attachment can't be judged by its name alone. Anything that isn't plainly
 * a document is tried as a picture; if it won't load as one, a link with no
 * extension is tried as a video (an MMS clip looks exactly like a photo), and
 * anything else falls back to a file card that opens it.
 */

/** What an attachment is shown as. */
export type AttachmentPreview = 'image' | 'video' | 'card';

const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', '3gp', '3gpp'];

const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx'];

/** The last path segment of a link, ignoring its query string. */
function urlFileName(url: string): string {
    let path = url;

    try {
        path = new URL(url, 'https://portal.invalid').pathname;
    } catch {
        path = url.split(/[?#]/)[0];
    }

    return path.split('/').pop() ?? '';
}

/** The file extension of a link, lowercased; empty when it has none. */
export function attachmentExtension(url: string): string {
    const name = urlFileName(url);

    return name.includes('.')
        ? (name.split('.').pop()?.toLowerCase() ?? '')
        : '';
}

/** How to show an attachment before anything has failed to load. */
export function firstAttachmentPreview(url: string): AttachmentPreview {
    const extension = attachmentExtension(url);

    if (VIDEO_EXTENSIONS.includes(extension)) {
        return 'video';
    }

    return DOCUMENT_EXTENSIONS.includes(extension) ? 'card' : 'image';
}

/** What to try next once an attachment fails to load as `failed`. */
export function attachmentPreviewAfterError(
    url: string,
    failed: AttachmentPreview,
): AttachmentPreview {
    return failed === 'image' && attachmentExtension(url) === ''
        ? 'video'
        : 'card';
}

/**
 * The name a file card shows. A Twilio link's last segment is an id like
 * "ME5f…", which means nothing to a client, so those read as "Attachment".
 */
export function attachmentName(url: string): string {
    const name = urlFileName(url);

    if (name === '' || !name.includes('.')) {
        return 'Attachment';
    }

    try {
        return decodeURIComponent(name);
    } catch {
        return name;
    }
}
