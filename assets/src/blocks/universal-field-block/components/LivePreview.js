/**
 * LivePreview — SSR preview component for Universal Enterprise CPT blocks.
 *
 * Fetches rendered HTML from the server via the /enterprise-cpt/v1/render-block
 * endpoint, debounced 500ms after attribute changes.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const previewCache = new Map();

export default function LivePreview({
    blockName,
    attributes,
    isSiteEditor = false,
    isEditing = false,
    fallbackSummary = [],
}) {
    const [html, setHtml] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const [refreshTick, setRefreshTick] = useState(0);
    const timerRef = useRef(null);
    const controllerRef = useRef(null);
    const attributesKey = JSON.stringify(attributes || {});
    const cacheKey = `${blockName}::${attributesKey}`;
    const debounceMs = isSiteEditor ? 1200 : 500;

    const fetchPreview = useCallback((force = false) => {
        if (!force && previewCache.has(cacheKey)) {
            setHtml(previewCache.get(cacheKey) || '');
            setError(null);
            setIsLoading(false);

            return;
        }

        // Abort any in-flight request.
        if (controllerRef.current) {
            controllerRef.current.abort();
        }

        const abortController =
            typeof AbortController !== 'undefined' ? new AbortController() : null;
        controllerRef.current = abortController;

        setIsLoading(true);
        setError(null);

        const path = '/enterprise-cpt/v1/render-block';

        apiFetch({
            path,
            method: 'POST',
            data: {
                block_name: blockName,
                attributes,
            },
            signal: abortController?.signal,
        })
            .then((response) => {
                if (response.error) {
                    setError(response.error);
                    setHtml('');
                } else {
                    const nextHtml = response.html || '';
                    previewCache.set(cacheKey, nextHtml);
                    setHtml(nextHtml);
                    setError(null);
                }
            })
            .catch((err) => {
                if (err?.name === 'AbortError') return;
                if (err?.data?.status === 404 && err?.message) {
                    setError(err.message);
                } else if (err?.message) {
                    setError(err.message);
                }
                setHtml('');
            })
            .finally(() => {
                setIsLoading(false);
            });
    }, [attributesKey, blockName, cacheKey, attributes]);

    // Debounce SSR requests. Site Editor uses a longer delay and pauses while editing.
    useEffect(() => {
        if (isSiteEditor && isEditing) {
            setIsLoading(false);

            return () => {
                if (controllerRef.current) {
                    controllerRef.current.abort();
                }
            };
        }

        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        timerRef.current = setTimeout(() => fetchPreview(false), debounceMs);

        return () => {
            clearTimeout(timerRef.current);
            if (controllerRef.current) {
                controllerRef.current.abort();
            }
        };
    }, [debounceMs, fetchPreview, isEditing, isSiteEditor, refreshTick]);

    const refreshPreview = () => {
        previewCache.delete(cacheKey);
        setRefreshTick((v) => v + 1);
    };

    if (error && isSiteEditor) {
        return (
            <div
                className="enterprise-cpt-live-preview enterprise-cpt-live-preview--fallback"
                style={{
                    border: '1px solid #dcdcde',
                    borderRadius: 2,
                    background: '#fff',
                    padding: 12,
                }}
            >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                    <strong style={{ fontSize: 13 }}>Site Editor fallback preview</strong>
                    <Button variant="secondary" isSmall onClick={refreshPreview}>
                        Refresh Preview
                    </Button>
                </div>
                <p style={{ marginTop: 8, marginBottom: 10, fontSize: 12, color: '#50575e' }}>
                    Live SSR preview is temporarily unavailable while editing. Showing field summary.
                </p>
                {fallbackSummary.length > 0 ? (
                    <ul style={{ margin: 0, paddingLeft: 16, fontSize: 13, color: '#1e1e1e' }}>
                        {fallbackSummary.map((item, i) => (
                            <li key={i}>{item}</li>
                        ))}
                    </ul>
                ) : (
                    <p style={{ margin: 0, fontSize: 13, color: '#1e1e1e' }}>No field values entered yet.</p>
                )}
            </div>
        );
    }

    if (isLoading) {
        return (
            <div
                className="enterprise-cpt-live-preview enterprise-cpt-live-preview--loading"
                style={{
                    position: 'relative',
                    minHeight: 60,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    opacity: 0.6,
                }}
            >
                <Spinner />
            </div>
        );
    }

    if (error) {
        return (
            <div
                className="enterprise-cpt-live-preview enterprise-cpt-live-preview--error"
                style={{
                    padding: 12,
                    background: '#fcf0f0',
                    border: '1px solid #d63638',
                    borderRadius: 2,
                    fontSize: 13,
                    color: '#8a1116',
                }}
            >
                {error}
            </div>
        );
    }

    return (
        <div className="enterprise-cpt-live-preview-wrap">
            {isSiteEditor && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
                    <Button variant="secondary" isSmall onClick={refreshPreview}>
                        Refresh Preview
                    </Button>
                </div>
            )}
            <div
                className="enterprise-cpt-live-preview"
                dangerouslySetInnerHTML={{ __html: html }}
            />
        </div>
    );
}
