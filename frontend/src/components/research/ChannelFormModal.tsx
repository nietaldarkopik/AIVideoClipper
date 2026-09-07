"use client";

import { useMemo, useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { Badge } from "@/components/ui/Badge";
import { api, ApiError } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useToastStore } from "@/store/toast";
import type {
  ChannelTemplate,
  ContentChannel,
  Platform,
  ResearchFrequency,
  ResearchSource,
} from "@/lib/types";

/**
 * Add / edit a research channel.
 *
 * Everything the engine needs is configured here — nothing about a channel,
 * its niche, its schedule or its sources is ever hardcoded in the backend, so a
 * brand-new channel works from this form alone with no deploy.
 */
export function ChannelFormModal({
  open,
  onClose,
  onSaved,
  channel,
}: {
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
  /** Null when creating. */
  channel?: ContentChannel | null;
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={channel ? `Edit ${channel.name}` : "Tambah Channel"}
      className="max-w-3xl"
    >
      {/*
        The body is mounted only while the modal is open and keyed by the channel,
        so every open starts from a fresh useState initializer. That is what keeps a
        cancelled edit from leaking into the next one WITHOUT a reset effect —
        setState inside an effect would cascade an extra render on every open.
      */}
      {open && (
        <ChannelForm
          key={channel?.id ?? "new"}
          channel={channel ?? null}
          onClose={onClose}
          onSaved={onSaved}
        />
      )}
    </Modal>
  );
}

function ChannelForm({
  channel,
  onClose,
  onSaved,
}: {
  channel: ContentChannel | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEditing = !!channel;
  const push = useToastStore((s) => s.push);

  const { data: platforms } = useApi<{ data: Platform[] }>("/research/platforms");
  const { data: templates } = useApi<{ data: ChannelTemplate[] }>(isEditing ? null : "/research/channel-templates");
  const { data: sources } = useApi<{ data: ResearchSource[] }>("/research/sources?enabled=1");

  const [form, setForm] = useState(() => (channel ? formFromChannel(channel) : emptyForm()));
  const [selectedSources, setSelectedSources] = useState<Record<number, boolean>>(() =>
    Object.fromEntries((channel?.research_sources ?? []).map((source) => [source.id, source.pivot?.enabled ?? true]))
  );
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const sourceList = useMemo(() => sources?.data ?? [], [sources]);

  function applyTemplate(templateKey: string) {
    const template = templates?.data.find((t) => t.key === templateKey);
    if (!template) return;

    const defaults = template.defaults ?? {};

    setForm((current) => ({
      ...current,
      niche: defaults.niche ?? current.niche,
      sub_niches: defaults.sub_niches?.join("\n") ?? current.sub_niches,
      keywords: defaults.keywords?.join("\n") ?? current.keywords,
      content_style: defaults.content_style?.join("\n") ?? current.content_style,
      content_types: defaults.content_types?.join("\n") ?? current.content_types,
      content_formats: defaults.content_formats?.join("\n") ?? current.content_formats,
      tone: defaults.tone ?? current.tone,
      research_frequency: defaults.research_frequency ?? current.research_frequency,
      research_times: defaults.research_times?.join(", ") ?? current.research_times,
      interval_hours: String(defaults.interval_hours ?? current.interval_hours),
      ideas_per_run: String(defaults.ideas_per_run ?? current.ideas_per_run),
      // The platform is a hint on the template; the user can still change it.
      platform_id:
        platforms?.data.find((p) => p.key === template.platform_key)?.id.toString() ?? current.platform_id,
    }));

    // Templates name their sources by key; map to the ids this install actually has.
    const keys = new Set(defaults.research_source_keys ?? []);
    setSelectedSources(
      Object.fromEntries(sourceList.filter((source) => keys.has(source.key)).map((source) => [source.id, true]))
    );
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true);
    setErrors({});

    const payload = {
      platform_id: Number(form.platform_id),
      name: form.name.trim(),
      handle: form.handle.trim() || null,
      description: form.description.trim() || null,
      language: form.language,
      timezone: form.timezone,
      niche: form.niche.trim() || null,
      sub_niches: toList(form.sub_niches),
      keywords: toList(form.keywords),
      excluded_keywords: toList(form.excluded_keywords),
      target_audience: form.target_audience.trim() || null,
      content_style: toList(form.content_style),
      content_types: toList(form.content_types),
      content_formats: toList(form.content_formats),
      tone: form.tone.trim() || null,
      scheduler_enabled: form.scheduler_enabled,
      research_frequency: form.research_frequency,
      research_times: toTimes(form.research_times),
      interval_hours: form.research_frequency === "every_n_hours" ? Number(form.interval_hours) : null,
      ideas_per_run: Number(form.ideas_per_run),
      min_relevance_score: Number(form.min_relevance_score),
      min_trend_score: Number(form.min_trend_score),
      research_sources: Object.entries(selectedSources)
        .filter(([, enabled]) => enabled)
        .map(([id], index) => ({
          research_source_id: Number(id),
          enabled: true,
          weight: 1,
          // Declaration order becomes execution priority server-side.
          priority: (index + 1) * 10,
          // Per-source configuration is edited on the channel detail page, so it is
          // omitted here rather than sent empty — which would wipe existing config.
          ...(channel?.research_sources?.find((s) => s.id === Number(id))?.pivot?.configuration
            ? { configuration: channel.research_sources.find((s) => s.id === Number(id))!.pivot!.configuration }
            : {}),
        })),
    };

    try {
      if (isEditing) {
        await api.patch(`/content-channels/${channel.id}`, payload);
        push("Channel diperbarui.", "success");
      } else {
        await api.post("/content-channels", payload);
        push("Channel dibuat.", "success");
      }
      onSaved();
      onClose();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.errors ?? {});
        push(error.message, "danger");
      } else {
        push("Gagal menyimpan channel.", "danger");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
        {!isEditing && templates?.data && templates.data.length > 0 && (
          <div>
            <Label>Mulai dari Template (opsional)</Label>
            <Select defaultValue="" onChange={(e) => e.target.value && applyTemplate(e.target.value)}>
              <option value="">Pilih template...</option>
              {templates.data.map((template) => (
                <option key={template.key} value={template.key}>
                  {template.name}
                </option>
              ))}
            </Select>
            <p className="mt-1 text-xs text-muted">
              Template hanya mengisi form. Semua nilai masih bisa kamu ubah sebelum disimpan.
            </p>
          </div>
        )}

        <Section title="Identitas">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Nama Channel" error={errors.name}>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </Field>
            <Field label="Platform" error={errors.platform_id}>
              <Select
                value={form.platform_id}
                onChange={(e) => setForm({ ...form, platform_id: e.target.value })}
                required
              >
                <option value="">Pilih platform...</option>
                {platforms?.data.map((platform) => (
                  <option key={platform.id} value={platform.id}>
                    {platform.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Handle" error={errors.handle}>
              <Input
                value={form.handle}
                onChange={(e) => setForm({ ...form, handle: e.target.value })}
                placeholder="@channel"
              />
            </Field>
            <Field label="Bahasa" error={errors.language}>
              <Select value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value })}>
                <option value="id">Indonesia</option>
                <option value="en">English</option>
              </Select>
            </Field>
          </div>
          <Field label="Deskripsi" error={errors.description}>
            <Textarea
              rows={2}
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </Field>
        </Section>

        <Section title="Niche & Audiens">
          <Field label="Niche Utama" error={errors.niche}>
            <Input
              value={form.niche}
              onChange={(e) => setForm({ ...form, niche: e.target.value })}
              placeholder="Teknologi & Pemrograman"
            />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <ListField
              label="Sub Niche"
              value={form.sub_niches}
              onChange={(value) => setForm({ ...form, sub_niches: value })}
            />
            <ListField
              label="Kata Kunci"
              value={form.keywords}
              onChange={(value) => setForm({ ...form, keywords: value })}
            />
            <ListField
              label="Kata Kunci Dikecualikan"
              value={form.excluded_keywords}
              onChange={(value) => setForm({ ...form, excluded_keywords: value })}
              help="Topik yang mengandung kata ini tidak akan pernah diusulkan."
            />
            <Field label="Target Audiens" error={errors.target_audience}>
              <Textarea
                rows={4}
                value={form.target_audience}
                onChange={(e) => setForm({ ...form, target_audience: e.target.value })}
              />
            </Field>
          </div>
        </Section>

        <Section title="Strategi Konten">
          <div className="grid gap-3 sm:grid-cols-3">
            <ListField
              label="Gaya Konten"
              value={form.content_style}
              onChange={(value) => setForm({ ...form, content_style: value })}
            />
            <ListField
              label="Tipe Konten"
              value={form.content_types}
              onChange={(value) => setForm({ ...form, content_types: value })}
            />
            <ListField
              label="Format Konten"
              value={form.content_formats}
              onChange={(value) => setForm({ ...form, content_formats: value })}
            />
          </div>
          <Field label="Tone" error={errors.tone}>
            <Input value={form.tone} onChange={(e) => setForm({ ...form, tone: e.target.value })} />
          </Field>
        </Section>

        <Section title="Scheduler">
          <label className="flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.scheduler_enabled}
              onChange={(e) => setForm({ ...form, scheduler_enabled: e.target.checked })}
              className="size-4 accent-[var(--accent,#7c5cff)]"
            />
            Aktifkan riset otomatis
          </label>

          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Frekuensi" error={errors.research_frequency}>
              <Select
                value={form.research_frequency}
                onChange={(e) =>
                  setForm({ ...form, research_frequency: e.target.value as ResearchFrequency })
                }
              >
                <option value="daily">Sekali sehari</option>
                <option value="twice_daily">Dua kali sehari</option>
                <option value="every_n_hours">Setiap N jam</option>
                <option value="custom">Jadwal khusus</option>
              </Select>
            </Field>

            {form.research_frequency === "every_n_hours" ? (
              <Field label="Interval (jam)" error={errors.interval_hours}>
                <Input
                  type="number"
                  min={1}
                  max={24}
                  value={form.interval_hours}
                  onChange={(e) => setForm({ ...form, interval_hours: e.target.value })}
                />
              </Field>
            ) : (
              <Field label="Jam Riset" error={errors["research_times.0"] ?? errors.research_times}>
                <Input
                  value={form.research_times}
                  onChange={(e) => setForm({ ...form, research_times: e.target.value })}
                  placeholder="06:00, 12:00, 18:00"
                />
              </Field>
            )}

            <Field label="Zona Waktu" error={errors.timezone}>
              <Input value={form.timezone} onChange={(e) => setForm({ ...form, timezone: e.target.value })} />
            </Field>
            <Field label="Ide per Riset" error={errors.ideas_per_run}>
              <Input
                type="number"
                min={1}
                max={20}
                value={form.ideas_per_run}
                onChange={(e) => setForm({ ...form, ideas_per_run: e.target.value })}
              />
            </Field>
            <Field label="Minimum Skor Relevansi" error={errors.min_relevance_score}>
              <Input
                type="number"
                min={0}
                max={100}
                value={form.min_relevance_score}
                onChange={(e) => setForm({ ...form, min_relevance_score: e.target.value })}
              />
            </Field>
            <Field label="Minimum Skor Trend" error={errors.min_trend_score}>
              <Input
                type="number"
                min={0}
                max={100}
                value={form.min_trend_score}
                onChange={(e) => setForm({ ...form, min_trend_score: e.target.value })}
              />
            </Field>
          </div>
          <p className="text-xs text-muted">
            Jam ditulis dalam format 24 jam (HH:MM) dan mengikuti zona waktu channel ini.
          </p>
        </Section>

        <Section title="Sumber Riset">
          <p className="text-xs text-muted">
            Pilih sumber untuk channel ini saja. Tidak semua channel perlu semua sumber — konfigurasi
            detail tiap sumber ada di halaman channel.
          </p>
          <div className="grid gap-2 sm:grid-cols-2">
            {sourceList.map((source) => (
              <label
                key={source.id}
                className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-border-subtle bg-surface p-2.5 text-sm"
              >
                <input
                  type="checkbox"
                  checked={!!selectedSources[source.id]}
                  onChange={(e) => setSelectedSources({ ...selectedSources, [source.id]: e.target.checked })}
                  className="mt-0.5 size-4 accent-[var(--accent,#7c5cff)]"
                />
                <span className="min-w-0">
                  <span className="flex items-center gap-1.5">
                    {source.name}
                    {source.requires_credentials && !source.is_configured && (
                      <Badge tone="warning" className="px-1.5 py-0 text-[10px]">
                        perlu API key
                      </Badge>
                    )}
                  </span>
                  {source.description && (
                    <span className="mt-0.5 block text-xs text-muted">{source.description}</span>
                  )}
                </span>
              </label>
            ))}
          </div>
        </Section>

        <div className="flex justify-end gap-2 border-t border-border-subtle pt-4">
          <Button type="button" variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" loading={saving}>
            {isEditing ? "Simpan Perubahan" : "Buat Channel"}
          </Button>
      </div>
    </form>
  );
}

function formFromChannel(channel: ContentChannel) {
  return {
    platform_id: String(channel.platform_id),
    name: channel.name,
    handle: channel.handle ?? "",
    description: channel.description ?? "",
    language: channel.language,
    timezone: channel.timezone,
    niche: channel.niche ?? "",
    sub_niches: channel.sub_niches.join("\n"),
    keywords: channel.keywords.join("\n"),
    excluded_keywords: channel.excluded_keywords.join("\n"),
    target_audience: channel.target_audience ?? "",
    content_style: channel.content_style.join("\n"),
    content_types: channel.content_types.join("\n"),
    content_formats: channel.content_formats.join("\n"),
    tone: channel.tone ?? "",
    scheduler_enabled: channel.scheduler_enabled,
    research_frequency: channel.research_frequency,
    research_times: channel.research_times.join(", "),
    interval_hours: String(channel.interval_hours ?? 6),
    ideas_per_run: String(channel.ideas_per_run),
    min_relevance_score: String(channel.min_relevance_score),
    min_trend_score: String(channel.min_trend_score),
  };
}

function emptyForm() {
  return {
    platform_id: "",
    name: "",
    handle: "",
    description: "",
    language: "id",
    timezone: "Asia/Jakarta",
    niche: "",
    sub_niches: "",
    keywords: "",
    excluded_keywords: "",
    target_audience: "",
    content_style: "",
    content_types: "",
    content_formats: "",
    tone: "",
    scheduler_enabled: true,
    research_frequency: "daily" as ResearchFrequency,
    research_times: "06:00",
    interval_hours: "6",
    ideas_per_run: "5",
    min_relevance_score: "0",
    min_trend_score: "0",
  };
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-3">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-muted">{title}</h3>
      {children}
    </div>
  );
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string[];
  children: React.ReactNode;
}) {
  return (
    <div>
      <Label>{label}</Label>
      {children}
      {error?.[0] && <p className="mt-1 text-xs text-danger">{error[0]}</p>}
    </div>
  );
}

/** A newline-separated list, which is far less fiddly than tag chips for bulk entry. */
function ListField({
  label,
  value,
  onChange,
  help,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  help?: string;
}) {
  return (
    <div>
      <Label>{label}</Label>
      <Textarea rows={4} value={value} onChange={(e) => onChange(e.target.value)} placeholder="Satu per baris" />
      {help && <p className="mt-1 text-xs text-muted">{help}</p>}
    </div>
  );
}

function toList(value: string): string[] {
  return value
    .split(/[\r\n]+/)
    .map((item) => item.trim())
    .filter(Boolean);
}

/** Accepts comma- or newline-separated times; the API validates the HH:MM shape. */
function toTimes(value: string): string[] {
  return value
    .split(/[\r\n,]+/)
    .map((item) => item.trim())
    .filter(Boolean);
}
