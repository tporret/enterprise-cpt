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
            <div className="enterprise-cpt-live-preview enterprise-cpt-live-preview--fallback">
                <div className="enterprise-cpt-live-preview__header">
                    <strong className="enterprise-cpt-live-preview__title">Site Editor fallback preview</strong>
                    <Button variant="secondary" isSmall onClick={refreshPreview}>
                        Refresh Preview
                    </Button>
                </div>
                <p className="enterprise-cpt-live-preview__message">
                    Live SSR preview is temporarily unavailable while editing. Showing field summary.
                </p>
                {fallbackSummary.length > 0 ? (
                    <ul className="enterprise-cpt-live-preview__list">
                        {fallbackSummary.map((item, i) => (
                            <li key={i}>{item}</li>
                        ))}
                    </ul>
                ) : (
                    <p className="enterprise-cpt-live-preview__empty">No field values entered yet.</p>
                )}
            </div>
        );
    }

    if (isLoading) {
        return (
            <div className="enterprise-cpt-live-preview enterprise-cpt-live-preview--loading">
                <Spinner />
            </div>
        );
    }

    if (error) {
        return (
            <div className="enterprise-cpt-live-preview enterprise-cpt-live-preview--error">
                {error}
            </div>
        );
    }

    return (
        <div className="enterprise-cpt-live-preview-wrap">
            {isSiteEditor && (
                <div className="enterprise-cpt-live-preview__actions">
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
