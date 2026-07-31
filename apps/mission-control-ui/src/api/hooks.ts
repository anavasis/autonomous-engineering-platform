import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiUser, setCsrfToken } from './client';

export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      const data = await api<{ user: ApiUser; csrfToken: string }>('/auth/me');
      setCsrfToken(data.csrfToken);
      return data;
    },
    retry: false,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { username: string; password: string }) =>
      api<{ user: ApiUser; csrfToken: string }>('/auth/login', {
        method: 'POST',
        body: JSON.stringify(body),
      }),
    onSuccess: (data) => {
      setCsrfToken(data.csrfToken);
      qc.setQueryData(['me'], data);
    },
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api<{ ok: boolean }>('/auth/logout', { method: 'POST' }),
    onSuccess: () => {
      setCsrfToken('');
      qc.clear();
    },
  });
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api<Record<string, unknown>>('/dashboard'),
    refetchInterval: 5000,
  });
}

export function useProjects() {
  return useQuery({
    queryKey: ['projects'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/projects'),
    refetchInterval: 8000,
  });
}

export function useMissions() {
  return useQuery({
    queryKey: ['missions'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/missions'),
    refetchInterval: 4000,
  });
}

export function useMission(id: string) {
  return useQuery({
    queryKey: ['mission', id],
    queryFn: () => api<Record<string, unknown>>(`/missions/${id}`),
    enabled: Boolean(id),
    refetchInterval: 3000,
  });
}

export function useTimeline(id: string) {
  return useQuery({
    queryKey: ['timeline', id],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/missions/${id}/timeline`),
    enabled: Boolean(id),
    refetchInterval: 3000,
  });
}

export function useApprovals() {
  return useQuery({
    queryKey: ['approvals'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/approvals'),
    refetchInterval: 4000,
  });
}

export function useArtifacts(missionId?: string) {
  const q = missionId ? `?missionId=${encodeURIComponent(missionId)}` : '';
  return useQuery({
    queryKey: ['artifacts', missionId ?? 'all'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/artifacts${q}`),
    refetchInterval: 8000,
  });
}

export function useArtifactWorkspace(workspaceId: string) {
  return useQuery({
    queryKey: ['artifact-workspace', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/artifacts/${workspaceId}`),
    enabled: Boolean(workspaceId),
  });
}

export function useValidation() {
  return useQuery({
    queryKey: ['validation'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/validation'),
    refetchInterval: 8000,
  });
}

export function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: () => api<Record<string, unknown>>('/settings'),
  });
}

export function useExecutionProviders() {
  return useQuery({
    queryKey: ['execution-providers'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/execution/providers'),
    refetchInterval: 10000,
  });
}

export function useMissionExecution(missionId: string) {
  return useQuery({
    queryKey: ['mission-execution', missionId],
    queryFn: () => api<{ session: Record<string, unknown> | null }>(`/missions/${missionId}/execution`),
    enabled: Boolean(missionId),
    refetchInterval: 3000,
  });
}

export function useExecutionEvents(sessionId: string) {
  return useQuery({
    queryKey: ['execution-events', sessionId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/execution/sessions/${sessionId}/events`),
    enabled: Boolean(sessionId),
    refetchInterval: 2500,
  });
}

export function useExecutionPrompt(sessionId: string) {
  return useQuery({
    queryKey: ['execution-prompt', sessionId],
    queryFn: () => api<Record<string, unknown>>(`/execution/sessions/${sessionId}/prompt`),
    enabled: Boolean(sessionId),
  });
}

export function useExecutionMetrics(sessionId: string) {
  return useQuery({
    queryKey: ['execution-metrics', sessionId],
    queryFn: () => api<Record<string, unknown>>(`/execution/sessions/${sessionId}/metrics`),
    enabled: Boolean(sessionId),
    refetchInterval: 3000,
  });
}

export function useExecutionSettings() {
  return useQuery({
    queryKey: ['execution-settings'],
    queryFn: () =>
      api<{ settings: Record<string, unknown>; providers: Array<Record<string, unknown>> }>(
        '/settings/execution',
      ),
  });
}

export function useWorkspaces(missionId?: string) {
  const q = missionId ? `?missionId=${encodeURIComponent(missionId)}` : '';
  return useQuery({
    queryKey: ['workspaces', missionId ?? 'all'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/workspaces${q}`),
    refetchInterval: 5000,
  });
}

export function useWorkspace(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/workspaces/${workspaceId}`),
    enabled: Boolean(workspaceId),
    refetchInterval: 4000,
  });
}

export function useWorkspaceTimeline(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace-timeline', workspaceId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/workspaces/${workspaceId}/timeline`),
    enabled: Boolean(workspaceId),
    refetchInterval: 4000,
  });
}

export function useWorkspaceMounts(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace-mounts', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/workspaces/${workspaceId}/mounts`),
    enabled: Boolean(workspaceId),
  });
}

export function useWorkspaceSize(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace-size', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/workspaces/${workspaceId}/size`),
    enabled: Boolean(workspaceId),
    refetchInterval: 5000,
  });
}

export function useMissionWorkspace(missionId: string) {
  return useQuery({
    queryKey: ['mission-workspace', missionId],
    queryFn: () => api<{ workspace: Record<string, unknown> | null }>(`/missions/${missionId}/workspace`),
    enabled: Boolean(missionId),
    refetchInterval: 4000,
  });
}

export function useWorkspaceSettings() {
  return useQuery({
    queryKey: ['workspace-settings'],
    queryFn: () => api<{ settings: Record<string, unknown> }>('/settings/workspaces'),
  });
}

export function usePatches(missionId?: string) {
  const q = missionId ? `?missionId=${encodeURIComponent(missionId)}` : '';
  return useQuery({
    queryKey: ['patches', missionId ?? 'all'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/patches${q}`),
    refetchInterval: 4000,
  });
}

export function usePatch(patchId: string) {
  return useQuery({
    queryKey: ['patch', patchId],
    queryFn: () => api<Record<string, unknown>>(`/patches/${patchId}`),
    enabled: Boolean(patchId),
    refetchInterval: 3500,
  });
}

export function usePatchDiff(patchId: string) {
  return useQuery({
    queryKey: ['patch-diff', patchId],
    queryFn: () => api<{ diff: string; diffHash: string }>(`/patches/${patchId}/diff`),
    enabled: Boolean(patchId),
  });
}

export function usePatchChecks(patchId: string) {
  return useQuery({
    queryKey: ['patch-checks', patchId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/patches/${patchId}/checks`),
    enabled: Boolean(patchId),
  });
}

export function usePatchReviews(patchId: string) {
  return useQuery({
    queryKey: ['patch-reviews', patchId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/patches/${patchId}/reviews`),
    enabled: Boolean(patchId),
  });
}

export function usePatchTimeline(patchId: string) {
  return useQuery({
    queryKey: ['patch-timeline', patchId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/patches/${patchId}/timeline`),
    enabled: Boolean(patchId),
    refetchInterval: 4000,
  });
}

export function usePatchReadiness(patchId: string) {
  return useQuery({
    queryKey: ['patch-readiness', patchId],
    queryFn: () => api<Record<string, unknown>>(`/patches/${patchId}/readiness`),
    enabled: Boolean(patchId),
    refetchInterval: 4000,
  });
}

export function useReviewQueue() {
  return useQuery({
    queryKey: ['review-queue'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/reviews/queue'),
    refetchInterval: 4000,
  });
}

export function useKnowledge(projectId?: string) {
  const qs = projectId ? `?projectId=${encodeURIComponent(projectId)}` : '';
  return useQuery({
    queryKey: ['knowledge', projectId ?? ''],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/knowledge${qs}`),
    refetchInterval: 5000,
  });
}

export function useKnowledgeDetail(knowledgeId: string) {
  return useQuery({
    queryKey: ['knowledge-detail', knowledgeId],
    queryFn: () => api<Record<string, unknown>>(`/knowledge/${knowledgeId}`),
    enabled: Boolean(knowledgeId),
  });
}

export function useKnowledgeLessons(projectId?: string) {
  const qs = projectId ? `?projectId=${encodeURIComponent(projectId)}` : '';
  return useQuery({
    queryKey: ['knowledge-lessons', projectId ?? ''],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/knowledge/lessons${qs}`),
    refetchInterval: 6000,
  });
}

export function useKnowledgeTimeline() {
  return useQuery({
    queryKey: ['knowledge-timeline'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/knowledge/timeline'),
    refetchInterval: 5000,
  });
}

export function useSimilarMissions(objective: string, projectId?: string) {
  const params = new URLSearchParams({ objective });
  if (projectId) params.set('projectId', projectId);
  return useQuery({
    queryKey: ['similar-missions', objective, projectId ?? ''],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/knowledge/similar/missions?${params}`),
    enabled: objective.trim().length >= 4,
    refetchInterval: 8000,
  });
}

export function useSimilarPatches(objective: string, projectId?: string) {
  const params = new URLSearchParams({ objective });
  if (projectId) params.set('projectId', projectId);
  return useQuery({
    queryKey: ['similar-patches', objective, projectId ?? ''],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/knowledge/similar/patches?${params}`),
    enabled: objective.trim().length >= 4,
    refetchInterval: 8000,
  });
}

export function useMissionMemory(missionId: string) {
  return useQuery({
    queryKey: ['mission-memory', missionId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/missions/${missionId}/memory`),
    enabled: Boolean(missionId),
    refetchInterval: 5000,
  });
}

export function usePlanningDashboard() {
  return useQuery({
    queryKey: ['planning-dashboard'],
    queryFn: () => api<{
      programCount: number;
      queueDepth: number;
      blocked: number;
      byStatus: Record<string, number>;
      items: Array<Record<string, unknown>>;
    }>('/planning/dashboard'),
    refetchInterval: 5000,
  });
}

export function usePrograms(status?: string) {
  const qs = status ? `?status=${encodeURIComponent(status)}` : '';
  return useQuery({
    queryKey: ['programs', status ?? ''],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/programs${qs}`),
    refetchInterval: 4000,
  });
}

export function useProgram(programId: string) {
  return useQuery({
    queryKey: ['program', programId],
    queryFn: () => api<Record<string, unknown>>(`/programs/${programId}`),
    enabled: Boolean(programId),
    refetchInterval: 4000,
  });
}

export function useProgramDependencies(programId: string) {
  return useQuery({
    queryKey: ['program-deps', programId],
    queryFn: () => api<{ items: Array<Record<string, unknown>>; waves: unknown }>(`/programs/${programId}/dependencies`),
    enabled: Boolean(programId),
  });
}

export function useProgramCriticalPath(programId: string) {
  return useQuery({
    queryKey: ['program-critical', programId],
    queryFn: () => api<Record<string, unknown>>(`/programs/${programId}/critical-path`),
    enabled: Boolean(programId),
  });
}

export function useProgramSchedule(programId: string) {
  return useQuery({
    queryKey: ['program-schedule', programId],
    queryFn: () => api<Record<string, unknown>>(`/programs/${programId}/schedule`),
    enabled: Boolean(programId),
    refetchInterval: 4000,
  });
}

export function useProgramAllocations(programId: string) {
  return useQuery({
    queryKey: ['program-allocations', programId],
    queryFn: () => api<Record<string, unknown>>(`/programs/${programId}/allocations`),
    enabled: Boolean(programId),
  });
}

export function useProgramQueue(programId: string) {
  return useQuery({
    queryKey: ['program-queue', programId],
    queryFn: () => api<Record<string, unknown>>(`/programs/${programId}/queue`),
    enabled: Boolean(programId),
    refetchInterval: 3500,
  });
}

export function useProgramTimeline(programId: string) {
  return useQuery({
    queryKey: ['program-timeline', programId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/programs/${programId}/timeline`),
    enabled: Boolean(programId),
    refetchInterval: 4000,
  });
}

export function useApprovalDecision() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: {
      missionId: string;
      kind: string;
      decision: 'approved' | 'rejected';
      rationale?: string;
      runId?: string;
      gateId?: string;
    }) => {
      if (input.kind === 'inspection') {
        return api(`/approvals/${input.missionId}/inspection`, {
          method: 'POST',
          body: JSON.stringify({
            decision: input.decision,
            rationale: input.rationale ?? '',
          }),
        });
      }
      if (input.kind === 'merge' || input.kind === 'commit') {
        return api(`/approvals/${input.missionId}/commit`, {
          method: 'POST',
          body: JSON.stringify({
            decision: input.decision,
            rationale: input.rationale ?? '',
          }),
        });
      }
      return api(`/approvals/${input.missionId}/gates/${input.gateId}`, {
        method: 'POST',
        body: JSON.stringify({
          decision: input.decision,
          runId: input.runId,
        }),
      });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['approvals'] });
      void qc.invalidateQueries({ queryKey: ['missions'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useAgents() {
  return useQuery({
    queryKey: ['agents'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/agents'),
    refetchInterval: 5000,
  });
}

export function useAgent(id: string) {
  return useQuery({
    queryKey: ['agent', id],
    queryFn: () => api<Record<string, unknown>>(`/agents/${encodeURIComponent(id)}`),
    enabled: Boolean(id),
    refetchInterval: 4000,
  });
}

export function useAgentDashboard() {
  return useQuery({
    queryKey: ['agent-dashboard'],
    queryFn: () => api<Record<string, unknown>>('/agents/dashboard'),
    refetchInterval: 5000,
  });
}

export function useAgentCapabilities() {
  return useQuery({
    queryKey: ['agent-capabilities'],
    queryFn: () => api<{ items: string[] }>('/agents/capabilities'),
  });
}

export function useAssignments(params: { missionId?: string; programId?: string; agentId?: string } = {}) {
  const q = new URLSearchParams();
  if (params.missionId) q.set('missionId', params.missionId);
  if (params.programId) q.set('programId', params.programId);
  if (params.agentId) q.set('agentId', params.agentId);
  const qs = q.toString();
  return useQuery({
    queryKey: ['assignments', params],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/assignments${qs ? `?${qs}` : ''}`),
    refetchInterval: 4000,
  });
}

export function useAgentAssignments(params: { missionId?: string; programId?: string; agentId?: string } = {}) {
  return useAssignments(params);
}

export function useMissionAssignments(missionId: string) {
  return useQuery({
    queryKey: ['mission-assignments', missionId],
    queryFn: () =>
      api<{ items: Array<Record<string, unknown>> }>(`/missions/${encodeURIComponent(missionId)}/assignments`),
    enabled: Boolean(missionId),
    refetchInterval: 4000,
  });
}

export function useProgramAssignments(programId: string) {
  return useQuery({
    queryKey: ['program-assignments', programId],
    queryFn: () =>
      api<{ items: Array<Record<string, unknown>> }>(`/programs/${encodeURIComponent(programId)}/assignments`),
    enabled: Boolean(programId),
    refetchInterval: 4000,
  });
}

export function useAgentTimeline(agentId: string) {
  return useQuery({
    queryKey: ['agent-timeline', agentId],
    queryFn: () =>
      api<{ items: Array<Record<string, unknown>> }>(`/agents/${encodeURIComponent(agentId)}/timeline`),
    enabled: Boolean(agentId),
    refetchInterval: 4000,
  });
}

export function useAgentMetrics(agentId: string) {
  return useQuery({
    queryKey: ['agent-metrics', agentId],
    queryFn: () => api<Record<string, unknown>>(`/agents/${encodeURIComponent(agentId)}/metrics`),
    enabled: Boolean(agentId),
  });
}

export function useAgentSessions(agentId: string) {
  return useQuery({
    queryKey: ['agent-sessions', agentId],
    queryFn: () =>
      api<{ items: Array<Record<string, unknown>> }>(`/agents/${encodeURIComponent(agentId)}/sessions`),
    enabled: Boolean(agentId),
  });
}

export function useAgentSettings() {
  return useQuery({
    queryKey: ['agent-settings'],
    queryFn: () => api<{ settings: Record<string, unknown> }>('/settings/agents'),
  });
}

export function useUpdateAgentSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      api<{ settings: Record<string, unknown> }>('/settings/agents', {
        method: 'PUT',
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['agent-settings'] });
      void qc.invalidateQueries({ queryKey: ['agent-dashboard'] });
      void qc.invalidateQueries({ queryKey: ['agents'] });
    },
  });
}

export function useOptimizationDashboard() {
  return useQuery({
    queryKey: ['optimization-dashboard'],
    queryFn: () => api<Record<string, unknown>>('/optimization/dashboard'),
    refetchInterval: 5000,
  });
}

export function useResources() {
  return useQuery({
    queryKey: ['resources'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/resources'),
  });
}

export function useCapacity() {
  return useQuery({
    queryKey: ['capacity'],
    queryFn: () => api<Record<string, unknown>>('/capacity'),
    refetchInterval: 5000,
  });
}

export function useBudgets() {
  return useQuery({
    queryKey: ['budgets'],
    queryFn: () => api<Record<string, unknown>>('/budgets'),
    refetchInterval: 5000,
  });
}

export function useCosts() {
  return useQuery({
    queryKey: ['costs'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/costs'),
    refetchInterval: 8000,
  });
}

export function useCostForecast() {
  return useQuery({
    queryKey: ['cost-forecast'],
    queryFn: () => api<Record<string, unknown>>('/costs/forecast'),
    refetchInterval: 8000,
  });
}

export function useProviderRanking() {
  return useQuery({
    queryKey: ['provider-ranking'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/optimization/providers/ranking'),
    refetchInterval: 5000,
  });
}

export function useOptimizationTimeline() {
  return useQuery({
    queryKey: ['optimization-timeline'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/optimization/timeline'),
    refetchInterval: 4000,
  });
}

export function useOptimizationMetrics() {
  return useQuery({
    queryKey: ['optimization-metrics'],
    queryFn: () => api<Record<string, unknown>>('/optimization/metrics'),
  });
}

export function useOptimizationSettings() {
  return useQuery({
    queryKey: ['optimization-settings'],
    queryFn: () => api<{ settings: Record<string, unknown> }>('/settings/optimization'),
  });
}

