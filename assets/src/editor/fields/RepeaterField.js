/**
 * RepeaterField — "Focus Canvas" component.
 *
 * List Mode:  Compact row summaries with Edit / Clone / Delete icon actions.
 *             Drag-and-drop reordering via native HTML5 DnD.
 * Focus Mode: Centered Modal titled "Editing [Row Name]" with Done / Discard
 *             footer buttons.
 * Media Intelligence: "Bulk Add from Media" creates a new row per selected
 *                     image, mapping the attachment_id to the first Image
 *                     subfield in the repeater schema.
 *
 * Dirty-state is managed via @wordpress/data: local working state is synced
 * to the parent onChange (which calls editPost meta) so the DB is only hit
 * when the Post is saved.
 */

import {
    Button,
    Modal,
    Spinner,
    TextControl,
    TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { pencil, copy, trash, dragHandle } from '@wordpress/icons';

// ── helpers ──────────────────────────────────────────────────────────────────

function parseRepeater(value) {
    if (Array.isArray(value)) return value;
    try {
        const parsed = JSON.parse(String(value || '[]'));
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function blankRow(schema) {
    const row = {};
    schema.forEach((col) => {
        if (col.type === 'number' || col.type === 'image') {
            row[col.name] = 0;
        } else {
            row[col.name] = '';
        }
    });
    return row;
}

// ── ImagePreview ─────────────────────────────────────────────────────────────

function ImagePreview({ attachmentId }) {
    const [src, setSrc] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const id = Number(attachmentId);
        if (!id) {
            setSrc(null);
            return;
        }
        setLoading(true);
        // Use wp.ajax to get attachment data without adding a REST dependency.
        if (window.wp?.ajax?.post) {
            window.wp.ajax
                .post('get-attachment', { id })
                .done((data) => {
                    const url =
                        data?.sizes?.medium?.url ||
                        data?.sizes?.thumbnail?.url ||
                        data?.url ||
                        '';
                    setSrc(url || null);
                })
                .fail(() => setSrc(null))
                .always(() => setLoading(false));
        } else {
            // Fallback: REST endpoint
            fetch(`${window.wpApiSettings?.root || '/wp-json/'}wp/v2/media/${id}`, {
                headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce || '' },
            })
                .then((r) => (r.ok ? r.json() : Promise.reject()))
                .then((media) => {
                    const url =
                        media?.media_details?.sizes?.medium?.source_url ||
                        media?.media_details?.sizes?.thumbnail?.source_url ||
                        media?.source_url ||
                        '';
                    setSrc(url || null);
                })
                .catch(() => setSrc(null))
                .finally(() => setLoading(false));
        }
    }, [attachmentId]);

    if (loading) return <Spinner />;
    if (!src) return null;

    return (
        <img
            src={src}
            alt=""
            className="enterprise-cpt-image-preview"
        />
    );
}

// ── SubfieldInput ────────────────────────────────────────────────────────────

function SubfieldInput({ subfield, value, onChange, disabled = false }) {
    const type = subfield.type || 'text';

    if (type === 'textarea') {
        return (
            <TextareaControl
                __nextHasNoMarginBottom
                label={subfield.label || subfield.name}
                value={String(value ?? '')}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'number') {
        return (
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label={subfield.label || subfield.name}
                type="number"
                value={String(value ?? '')}
                disabled={disabled}
                onChange={(v) => onChange(v === '' ? '' : Number(v))}
            />
        );
    }

    if (type === 'email') {
        return (
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label={subfield.label || subfield.name}
                type="email"
                value={String(value ?? '')}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'image') {
        const attachmentId = Number(value) || 0;

        const openMedia = () => {
            if (disabled) return;
            if (!window.wp?.media) return;
            const frame = window.wp.media({
                title: subfield.label || 'Select Image',
                multiple: false,
                library: { type: 'image' },
            });
            frame.on('select', () => {
                const att = frame.state().get('selection').first().toJSON();
                onChange(att.id);
            });
            frame.open();
        };

        return (
            <div className="enterprise-cpt-image-input">
                <p className="enterprise-cpt-image-input__label">
                    {subfield.label || subfield.name}
                </p>
                <ImagePreview attachmentId={attachmentId} />
                <div className="enterprise-cpt-image-input__actions">
                    <Button variant="secondary" isSmall disabled={disabled} onClick={openMedia}>
                        {attachmentId ? 'Replace Image' : 'Select Image'}
                    </Button>
                    {attachmentId > 0 && (
                        <Button
                            variant="tertiary"
                            isDestructive
                            isSmall
                            disabled={disabled}
                            onClick={() => onChange(0)}
                        >
                            Remove
                        </Button>
                    )}
                </div>
            </div>
        );
    }

    // Default: text
    return (
        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={subfield.label || subfield.name}
            value={String(value ?? '')}
            disabled={disabled}
            onChange={onChange}
        />
    );
}

// ── RepeaterField (Focus Canvas) ─────────────────────────────────────────────

export default function RepeaterField({ field, value, onChange, disabled = false }) {
    const schema = Array.isArray(field.rows) ? field.rows : [];
    const [rows, setRows] = useState(() => parseRepeater(value));
    const [focusedIndex, setFocusedIndex] = useState(null);
    const [snapshot, setSnapshot] = useState(null);
    const [dragOverIndex, setDragOverIndex] = useState(null);
    const lastExternalValue = useRef(value);
    const dragItem = useRef(null);

    const { createNotice } = useDispatch('core/notices');

    // Sync inbound value changes (e.g. undo, external revert).
    useEffect(() => {
        if (value !== lastExternalValue.current) {
            lastExternalValue.current = value;
            setRows(parseRepeater(value));
        }
    }, [value]);

    // Commit working rows → parent meta via @wordpress/data (editPost).
    const commitRows = useCallback(
        (nextRows) => {
            setRows(nextRows);
            const json = JSON.stringify(nextRows);
            lastExternalValue.current = json;
            onChange(json);
        },
        [onChange],
    );

    // ── row mutations ────────────────────────────────────────────────────

    const addRow = () => {
        if (disabled) return;

        const next = [...rows, blankRow(schema)];
        commitRows(next);
        setSnapshot(JSON.stringify(blankRow(schema)));
        setFocusedIndex(next.length - 1);
    };

    const removeRow = (idx) => {
        if (disabled) return;

        const next = rows.filter((_, i) => i !== idx);
        commitRows(next);
        if (focusedIndex === idx) {
            setFocusedIndex(null);
            setSnapshot(null);
        } else if (focusedIndex !== null && focusedIndex > idx) {
            setFocusedIndex(focusedIndex - 1);
        }
    };

    const cloneRow = (idx) => {
        if (disabled || idx < 0 || idx >= rows.length) return;

        // Deep clone to avoid object/array reference sharing between rows.
        const cloned = JSON.parse(JSON.stringify(rows[idx] ?? {}));
        const next = [...rows];
        next.splice(idx + 1, 0, cloned);
        commitRows(next);

        if (focusedIndex !== null) {
            if (focusedIndex > idx) {
                setFocusedIndex(focusedIndex + 1);
            } else if (focusedIndex === idx) {
                setFocusedIndex(idx + 1);
            }
        }

        createNotice('info', 'Row cloned successfully.', {
            type: 'snackbar',
            isDismissible: true,
        });
    };

    const updateCell = (rowIdx, colName, val) => {
        if (disabled) return;

        commitRows(
            rows.map((row, i) =>
                i === rowIdx ? { ...row, [colName]: val } : row,
            ),
        );
    };

    // ── drag-and-drop sorting ────────────────────────────────────────────

    const handleDragStart = (e, idx) => {
        if (disabled) return;

        dragItem.current = idx;
        e.dataTransfer.effectAllowed = 'move';
    };

    const handleDragEnter = (idx) => {
        if (disabled) return;

        setDragOverIndex(idx);
    };

    const handleDragEnd = () => {
        if (disabled) return;

        const from = dragItem.current;
        const to = dragOverIndex;
        dragItem.current = null;
        setDragOverIndex(null);

        if (from === null || to === null || from === to) return;
        if (from < 0 || from >= rows.length || to < 0 || to >= rows.length) return;

        const next = [...rows];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        commitRows(next);

        if (focusedIndex === from) setFocusedIndex(to);
    };

    // ── modal open / close ───────────────────────────────────────────────

    const openFocus = (idx) => {
        setSnapshot(JSON.stringify(rows[idx]));
        setFocusedIndex(idx);
    };

    const closeFocus = () => {
        setFocusedIndex(null);
        setSnapshot(null);
    };

    const discardChanges = () => {
        if (disabled) {
            closeFocus();
            return;
        }

        if (focusedIndex !== null && snapshot !== null) {
            const restored = JSON.parse(snapshot);
            commitRows(
                rows.map((row, i) => (i === focusedIndex ? restored : row)),
            );
        }
        closeFocus();
    };

    // ── row summary for list mode ────────────────────────────────────────

    const rowSummary = (row) => {
        if (schema.length === 0) return '(empty)';
        const firstVal = String(row[schema[0].name] ?? '').trim();
        return firstVal || '(empty)';
    };

    // ── Media Intelligence: Bulk Add from Media ──────────────────────────

    const bulkAddFromMedia = () => {
        if (disabled || !window.wp?.media) return;

        const frame = window.wp.media({
            title: 'Select Images',
            multiple: true,
            library: { type: 'image' },
        });

        frame.on('select', () => {
            const attachments = frame.state().get('selection').toJSON();
            const imageSubfield = schema.find((col) => col.type === 'image');

            if (!imageSubfield || !attachments.length) return;

            const newRows = attachments.map((att) => {
                const row = blankRow(schema);
                row[imageSubfield.name] = att.id;
                return row;
            });

            commitRows([...rows, ...newRows]);
        });

        frame.open();
    };

    const hasImageSubfield = schema.some((col) => col.type === 'image');
    const isFocused =
        focusedIndex !== null && focusedIndex >= 0 && focusedIndex < rows.length;

    // ── render ───────────────────────────────────────────────────────────

    return (
        <div className="enterprise-cpt-repeater">
            {/* Header */}
            <div className="enterprise-cpt-repeater__header">
                <p className="enterprise-cpt-repeater__title">{field.label}</p>
                <div className="enterprise-cpt-repeater__header-actions">
                    <Button variant="primary" isSmall disabled={disabled} onClick={addRow}>
                        + Add Row
                    </Button>
                    {hasImageSubfield && (
                        <Button
                            variant="secondary"
                            isSmall
                            disabled={disabled}
                            onClick={bulkAddFromMedia}
                        >
                            Bulk Add from Media
                        </Button>
                    )}
                </div>
            </div>

            {field.help && (
                <p className="enterprise-cpt-field-help">
                    {field.help}
                </p>
            )}

            {/* List Mode */}
            {rows.length === 0 ? (
                <p className="enterprise-cpt-repeater__empty-state">
                    No rows yet.
                </p>
            ) : (
                <div className="enterprise-cpt-repeater__list">
                    {rows.map((row, idx) => (
                        <div
                            key={idx}
                            draggable={!disabled}
                            onDragStart={(e) => handleDragStart(e, idx)}
                            onDragEnter={() => handleDragEnter(idx)}
                            onDragEnd={handleDragEnd}
                            onDragOver={(e) => e.preventDefault()}
                            className={`enterprise-cpt-repeater-row ${dragOverIndex === idx ? 'enterprise-cpt-repeater-row--drag-over' : ''} ${disabled ? 'enterprise-cpt-repeater-row--disabled' : ''} ${dragItem.current === idx ? 'enterprise-cpt-repeater-row--dragging' : ''}`}
                        >
                            {/* Drag handle */}
                            <span className="enterprise-cpt-repeater-handle">
                                <Button
                                    icon={dragHandle}
                                    label="Drag to reorder"
                                    isSmall
                                    disabled={disabled}
                                    className="enterprise-cpt-repeater-handle__button"
                                />
                            </span>

                            {/* Summary */}
                            <div className="enterprise-cpt-repeater-row__summary-wrapper">
                                <span className="enterprise-cpt-repeater-row__summary">
                                    {rowSummary(row)}
                                </span>
                            </div>

                            {/* Actions: Edit / Clone / Delete */}
                            <div className="enterprise-cpt-repeater-row__actions">
                                <Button
                                    icon={pencil}
                                    label="Edit"
                                    isSmall
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        openFocus(idx);
                                    }}
                                />
                                <Button
                                    icon={copy}
                                    label="Clone"
                                    isSmall
                                    disabled={disabled}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        cloneRow(idx);
                                    }}
                                />
                                <Button
                                    icon={trash}
                                    label="Delete"
                                    isSmall
                                    isDestructive
                                    disabled={disabled}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        removeRow(idx);
                                    }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Focus Mode — Centered Modal */}
            {isFocused && (
                <Modal
                    title={`Editing ${rowSummary(rows[focusedIndex])}`}
                    onRequestClose={closeFocus}
                >
                    <div className="enterprise-cpt-repeater-modal__content">
                        {schema.map((col) => (
                            <SubfieldInput
                                key={col.name}
                                subfield={col}
                                value={rows[focusedIndex][col.name]}
                                disabled={disabled}
                                onChange={(val) =>
                                    updateCell(focusedIndex, col.name, val)
                                }
                            />
                        ))}
                    </div>

                    {/* Footer: Discard / Done */}
                    <div className="enterprise-cpt-repeater-modal__footer">
                        {!disabled && (
                            <Button variant="tertiary" onClick={discardChanges}>
                                Discard Changes
                            </Button>
                        )}
                        <Button variant="primary" onClick={closeFocus}>
                            Done
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
}
